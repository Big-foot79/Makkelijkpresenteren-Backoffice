<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWooWebhook;
use App\Models\ShopConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives WooCommerce webhooks. Each delivery is signed with the webhook secret
 * (X-WC-Webhook-Signature = base64 HMAC-SHA256 of the raw body); unsigned or wrongly signed
 * requests are refused. The work itself happens on the queue, so the shop gets a quick 202.
 */
class WooWebhookController
{
    private const TOPICS = ['product.created', 'product.updated', 'product.deleted', 'product.restored', 'order.created', 'order.updated'];

    public function __invoke(Request $request): Response
    {
        $topic = (string) $request->header('X-WC-Webhook-Topic');

        // WooCommerce pings the URL once when a webhook is saved (body "webhook_id=…", no topic).
        if ($topic === '') {
            return response('ok', 200);
        }

        $secret = ShopConnection::current()?->webhook_secret;
        $signature = (string) $request->header('X-WC-Webhook-Signature');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), (string) $secret, true));

        if (blank($secret) || $signature === '' || ! hash_equals($expected, $signature)) {
            return response('invalid signature', 401);
        }

        if (in_array($topic, self::TOPICS, true) && is_array($payload = $request->json()->all())) {
            ProcessWooWebhook::dispatch($topic, $payload);
        }

        return response('accepted', 202);
    }
}
