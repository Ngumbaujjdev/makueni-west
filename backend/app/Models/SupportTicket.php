<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SupportTicket extends Model implements Auditable, HasMedia
{
    use HasFactory, \OwenIt\Auditing\Auditable, InteractsWithMedia;

    protected $fillable = [
        'ticket_number',
        'user_id',
        'name',
        'email',
        'phone',
        'category',
        'priority',
        'subject',
        'message',
        'status',
        'assigned_to',
        'resolved_at',
        'resolved_by',
        'admin_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | SPATIE MEDIA LIBRARY CONFIGURATION
    |--------------------------------------------------------------------------
    */

    /**
     * Register media collections for file attachments
     */
   /**
 * Register media collections for file attachments
 */
public function registerMediaCollections(): void
{
    $this->addMediaCollection('attachments')
        ->acceptsMimeTypes([
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
}

    /*
    |--------------------------------------------------------------------------
    | AUDIT CONFIGURATION
    |--------------------------------------------------------------------------
    */

    /**
     * Attributes to include in audit
     */
    protected $auditInclude = [
        'category',
        'priority',
        'subject',
        'message',
        'status',
        'assigned_to',
        'resolved_at',
        'resolved_by',
        'admin_notes',
    ];

    /**
     * Attributes to exclude from audit
     */
    protected $auditExclude = [
        'created_at',
        'updated_at',
    ];

    /**
     * Generate custom audit tags
     */
    public function generateTags(): array
    {
        return [
            'support_ticket',
            $this->ticket_number,
            $this->category,
        ];
    }

    /**
     * Transform audit data before storing
     */
    public function transformAudit(array $data): array
    {
        // Add metadata
        $data['app_version'] = config('app.version', '1.0.0');

        // Create custom event names based on what changed
        if ($data['event'] === 'updated') {

            // Status change event
            if (isset($data['new_values']['status'])) {
                $oldStatus = $data['old_values']['status'] ?? 'unknown';
                $newStatus = $data['new_values']['status'];
                $data['event'] = "ticket_status_changed_{$oldStatus}_to_{$newStatus}";
            }

            // Priority change event
            if (isset($data['new_values']['priority'])) {
                $data['event'] = 'ticket_priority_changed';
            }

            // Assignment event
            if (isset($data['new_values']['assigned_to'])) {
                $data['event'] = 'ticket_assigned';
            }

            // Resolution event
            if (isset($data['new_values']['resolved_at'])) {
                $data['event'] = 'ticket_resolved';
            }

            // Admin notes added
            if (isset($data['new_values']['admin_notes'])) {
                $data['event'] = 'ticket_notes_added';
            }
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    /**
     * User who submitted the ticket (null if guest)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Admin/Support staff assigned to ticket
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Admin who resolved the ticket
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    /**
     * Generate unique ticket number
     */
    public static function generateTicketNumber(): string
    {
        $year = now()->year;
        $lastTicket = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        $number = $lastTicket ? (int) substr($lastTicket->ticket_number, -4) + 1 : 1;
        return 'SUP-' . $year . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Check if ticket is open
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Check if ticket is resolved
     */
    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'closed']);
    }

    /**
     * Get all attachments with URLs
     */
    public function getAttachmentsWithUrls()
    {
        return $this->getMedia('attachments')->map(function ($media) {
            return [
                'id' => $media->id,
                'name' => $media->file_name,
                'size' => $media->size,
                'mime_type' => $media->mime_type,
                'url' => $media->getUrl(),
                'preview_url' => $media->hasGeneratedConversion('thumb')
                    ? $media->getUrl('thumb')
                    : $media->getUrl(),
                'uploaded_at' => $media->created_at->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Get ticket activity log from audits
     */
    public function getActivityLog($limit = 50)
    {
        return $this->audits()
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'id' => $audit->id,
                    'event' => $audit->event,
                    'changed_by' => $audit->user ? [
                        'id' => $audit->user->id,
                        'name' => $audit->user->full_name,
                    ] : 'System',
                    'old_values' => $audit->old_values,
                    'new_values' => $audit->new_values,
                    'ip_address' => $audit->ip_address,
                    'user_agent' => $audit->user_agent,
                    'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                ];
            });
    }

    /**
     * Get status change history
     */
    public function getStatusHistory()
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'like', 'ticket_status_changed%');
            })
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->get()
            ->map(function ($audit) {
                return [
                    'id' => $audit->id,
                    'event' => $audit->event,
                    'old_status' => $audit->old_values['status'] ?? null,
                    'new_status' => $audit->new_values['status'] ?? null,
                    'changed_by' => $audit->user ? [
                        'id' => $audit->user->id,
                        'name' => $audit->user->full_name,
                    ] : 'System',
                    'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                ];
            });
    }
}