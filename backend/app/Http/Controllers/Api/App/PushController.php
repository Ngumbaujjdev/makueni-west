<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Accounting\Paybill;
use App\Services\Push\PushSender;
use App\Services\Settings\Settings;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The phone app's extras (docs/specs/mobile-app-spec.md, M12): push sign-up and
 * the church's giving QR code.
 */
class PushController extends Controller
{
    /** GET /app/push/key - the public key phones subscribe with, or ready:false. */
    public function key(PushSender $sender, Settings $settings): JsonResponse
    {
        return successResponse('Push', $sender->ready()
            ? ['ready' => true, 'public_key' => $settings->system('push.vapid_public')]
            : ['ready' => false]);
    }

    /** POST /app/push/subscribe {endpoint, keys{p256dh, auth}} - this phone, for this person. */
    public function subscribe(Request $request): JsonResponse
    {
        $d = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);
        // An endpoint belongs to one browser: whoever signs in on it last gets its pushes.
        $sub = PushSubscription::updateOrCreate(['endpoint' => $d['endpoint']], [
            'user_id' => $request->user()->id, 'p256dh' => $d['keys']['p256dh'], 'auth' => $d['keys']['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return successResponse('Notifications are on for this phone', ['id' => $sub->id]);
    }

    /** DELETE /app/push/subscribe {endpoint} - only the person's own. */
    public function unsubscribe(Request $request): JsonResponse
    {
        $d = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);
        PushSubscription::where('endpoint', $d['endpoint'])->where('user_id', $request->user()->id)->delete();

        return successResponse('Notifications are off for this phone', null);
    }

    /** GET /app/giving/qr - an SVG QR of the church's giving link (the same link as accounting/giving). */
    public function givingQr(Request $request): Response|JsonResponse
    {
        $place = PlaceAccess::place($request->user());
        if (! $place || ! AccountingAccess::canRead($request->user(), $place)) {
            return errorResponse("Your role can't see this place's giving.", 403);
        }
        $link = config('app.frontend_url').'/give.php?c='.Paybill::code($place);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd)))->writeString($link);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600']);
    }
}
