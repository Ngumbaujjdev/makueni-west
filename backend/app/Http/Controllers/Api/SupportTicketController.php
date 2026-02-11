<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SupportTicketController extends Controller
{
    /**
     * Submit a new support ticket
     * Works for both authenticated users and guests
     */
  /**
 * Submit a new support ticket
 * Works for both authenticated users and guests
 * Auto-assigns to Global Admin if available
 */
public function store(Request $request)
{
    // Check if user is authenticated
    $user = $request->user();
    $isAuthenticated = !is_null($user);

    // Validation rules
    $rules = [
        'category' => 'required|in:account_issues,technical_support,how_to_guides,general_inquiry',
        'priority' => 'required|in:low,medium,high,urgent',
        'subject' => 'required|string|max:500',
        'message' => 'required|string',
        'attachments.*' => 'nullable|file|mimes:jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:5120', // 5MB
    ];

    // For guests, require name, email, phone
    if (!$isAuthenticated) {
        $rules['name'] = 'required|string|max:255';
        $rules['email'] = 'required|email|max:255';
        $rules['phone'] = 'required|string|max:20';
    }

    $validator = Validator::make($request->all(), $rules);

    if ($validator->fails()) {
        return validationErrorResponse($validator->errors());
    }

    try {
        // Generate ticket number
        $ticketNumber = SupportTicket::generateTicketNumber();

        // ✅ Find Global Admin
        $superAdminConfig = \App\Models\SuperAdminConfig::where('global_access', true)->first();
        $globalAdminId = $superAdminConfig ? $superAdminConfig->user_id : null;

        // Prepare ticket data
        $ticketData = [
            'ticket_number' => $ticketNumber,
            'category' => $request->category,
            'priority' => $request->priority,
            'subject' => $request->subject,
            'message' => $request->message,
        ];

        // ✅ Auto-assign to Global Admin if exists
        if ($globalAdminId) {
            $ticketData['assigned_to'] = $globalAdminId;
            $ticketData['status'] = 'in_progress'; // Auto-change status when assigned
        } else {
            $ticketData['assigned_to'] = null;
            $ticketData['status'] = 'open'; // No admin available, stays open
        }

        // If authenticated, use user data
        if ($isAuthenticated) {
            $ticketData['user_id'] = $user->id;
            $ticketData['name'] = $user->full_name;
            $ticketData['email'] = $user->email;
            $ticketData['phone'] = $user->phone;
        } else {
            // Guest submission
            $ticketData['user_id'] = null;
            $ticketData['name'] = $request->name;
            $ticketData['email'] = $request->email;
            $ticketData['phone'] = $request->phone;
        }

        // Create ticket
        $ticket = SupportTicket::create($ticketData);

        // Handle file attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $ticket->addMedia($file)
                    ->toMediaCollection('attachments');
            }
        }

        // Load ticket with relationships
        $ticket->load(['user', 'assignedTo', 'resolvedBy']);

        return successResponse('Support ticket submitted successfully', [
            'ticket_number' => $ticket->ticket_number,
            'ticket_id' => $ticket->id,
            'status' => $ticket->status,
            'assigned_to' => $ticket->assignedTo ? $ticket->assignedTo->full_name : null,
            'created_at' => $ticket->created_at->format('Y-m-d H:i:s'),
            'attachments_count' => $ticket->getMedia('attachments')->count(),
        ], 201);

    } catch (\Exception $e) {
        Log::error('Support ticket creation failed', [
            'error' => $e->getMessage(),
            'user_id' => $user?->id,
        ]);

        return serverErrorResponse('Failed to submit support ticket', $e->getMessage());
    }
}
    /**
     * Get all tickets for authenticated user
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $tickets = SupportTicket::where('user_id', $user->id)
                ->with(['assignedTo:id,firstname,lastname', 'resolvedBy:id,firstname,lastname'])
                ->withCount('media')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return successResponse('Tickets retrieved successfully', $tickets);

        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve tickets', $e->getMessage());
        }
    }

    /**
     * Get a single ticket by ID
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            $ticket = SupportTicket::with([
                'user:id,firstname,lastname,email,phone',
                'assignedTo:id,firstname,lastname',
                'resolvedBy:id,firstname,lastname'
            ])->findOrFail($id);

            // Check if user owns this ticket (guests can't view)
            if ($ticket->user_id !== $user->id) {
                return errorResponse('Unauthorized access', 403);
            }

            // Get attachments with URLs
            $attachments = $ticket->getAttachmentsWithUrls();

            // Get activity log
            $activityLog = $ticket->getActivityLog();

            return successResponse('Ticket retrieved successfully', [
                'ticket' => $ticket,
                'attachments' => $attachments,
                'activity_log' => $activityLog,
            ]);

        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve ticket', $e->getMessage());
        }
    }

    /**
     * Get ticket activity log
     */
    public function activityLog(Request $request, $id)
    {
        try {
            $user = $request->user();

            $ticket = SupportTicket::findOrFail($id);

            // Check if user owns this ticket
            if ($ticket->user_id !== $user->id) {
                return errorResponse('Unauthorized access', 403);
            }

            $limit = $request->input('limit', 50);
            $activityLog = $ticket->getActivityLog($limit);

            return successResponse('Activity log retrieved successfully', $activityLog);

        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve activity log', $e->getMessage());
        }
    }

    /**
     * Get ticket status history
     */
    public function statusHistory(Request $request, $id)
    {
        try {
            $user = $request->user();

            $ticket = SupportTicket::findOrFail($id);

            // Check if user owns this ticket
            if ($ticket->user_id !== $user->id) {
                return errorResponse('Unauthorized access', 403);
            }

            $statusHistory = $ticket->getStatusHistory();

            return successResponse('Status history retrieved successfully', $statusHistory);

        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve status history', $e->getMessage());
        }
    }
    /**
 * Update ticket status
 * Admin only
 */
public function updateStatus(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'status' => 'required|in:open,in_progress,resolved,closed',
    ]);

    if ($validator->fails()) {
        return validationErrorResponse($validator->errors());
    }

    try {
        $ticket = SupportTicket::findOrFail($id);

        // Update status
        $ticket->update([
            'status' => $request->status,
        ]);

        // If resolved or closed, set timestamps
        if ($request->status === 'resolved' || $request->status === 'closed') {
            $ticket->update([
                'resolved_at' => now(),
                'resolved_by' => $request->user()->id,
            ]);
        }

        return successResponse('Ticket status updated successfully', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'old_status' => $ticket->getOriginal('status'),
            'new_status' => $ticket->status,
        ]);

    } catch (\Exception $e) {
        return serverErrorResponse('Failed to update ticket status', $e->getMessage());
    }
}
/**
 * Resolve ticket
 * Admin only
 */
public function resolveTicket(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'resolution_notes' => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return validationErrorResponse($validator->errors());
    }

    try {
        $ticket = SupportTicket::findOrFail($id);

        // Add resolution notes if provided
        if ($request->has('resolution_notes')) {
            $adminName = $request->user()->full_name;
            $timestamp = now()->format('Y-m-d H:i:s');
            $resolutionNote = "[$timestamp] $adminName (RESOLVED): {$request->resolution_notes}";

            $existingNotes = $ticket->admin_notes;
            $updatedNotes = $existingNotes
                ? $existingNotes . "\n\n" . $resolutionNote
                : $resolutionNote;

            $ticket->admin_notes = $updatedNotes;
        }

        $ticket->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return successResponse('Ticket resolved successfully', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
            'resolved_by' => $ticket->resolvedBy->full_name,
            'resolved_at' => $ticket->resolved_at->format('Y-m-d H:i:s'),
        ]);

    } catch (\Exception $e) {
        return serverErrorResponse('Failed to resolve ticket', $e->getMessage());
    }
}
/**
 * Add admin notes to ticket
 * Admin only
 */
public function addNotes(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'notes' => 'required|string',
    ]);

    if ($validator->fails()) {
        return validationErrorResponse($validator->errors());
    }

    try {
        $ticket = SupportTicket::findOrFail($id);

        // Append notes with timestamp and admin name
        $adminName = $request->user()->full_name;
        $timestamp = now()->format('Y-m-d H:i:s');
        $newNote = "[$timestamp] $adminName: {$request->notes}";

        $existingNotes = $ticket->admin_notes;
        $updatedNotes = $existingNotes
            ? $existingNotes . "\n\n" . $newNote
            : $newNote;

        $ticket->update([
            'admin_notes' => $updatedNotes,
        ]);

        return successResponse('Admin notes added successfully', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'admin_notes' => $ticket->admin_notes,
        ]);

    } catch (\Exception $e) {
        return serverErrorResponse('Failed to add admin notes', $e->getMessage());
    }
}
/**
 * Resolve ticket
 * Admin only
 */
/**
 * Close ticket
 * Admin only
 */
public function closeTicket(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'closing_notes' => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return validationErrorResponse($validator->errors());
    }

    try {
        $ticket = SupportTicket::findOrFail($id);

        // Add closing notes if provided
        if ($request->has('closing_notes')) {
            $adminName = $request->user()->full_name;
            $timestamp = now()->format('Y-m-d H:i:s');
            $closingNote = "[$timestamp] $adminName (CLOSED): {$request->closing_notes}";

            $existingNotes = $ticket->admin_notes;
            $updatedNotes = $existingNotes
                ? $existingNotes . "\n\n" . $closingNote
                : $closingNote;

            $ticket->admin_notes = $updatedNotes;
        }

        $ticket->update([
            'status' => 'closed',
            'resolved_at' => $ticket->resolved_at ?? now(),
            'resolved_by' => $ticket->resolved_by ?? $request->user()->id,
        ]);

        return successResponse('Ticket closed successfully', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
        ]);

    } catch (\Exception $e) {
        return serverErrorResponse('Failed to close ticket', $e->getMessage());
    }
}

}
