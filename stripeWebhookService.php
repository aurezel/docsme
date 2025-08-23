<?php

require_once('vendor/autoload.php');

use Stripe\Stripe;
use Stripe\WebhookEndpoint;

class StripeWebhookService
{
    public function __construct($api_key)
    {
        Stripe::setApiKey($api_key);
    }

    /**
     * 创建一个 Webhook
     *
     * @param string $domain 完整域名，如 https://example.com
     * @param string $path   Webhook 路径
     * @param array  $events 要监听的事件
     * @return array
     */
    public function createWebhook(string $domain, string $path = '/v1/StripeBankNotify', int $type=1): array
    {
        try {
            $url = rtrim($domain, '/') . "/".ltrim($path);

            $existing = WebhookEndpoint::all();
            foreach ($existing->data as $endpoint) {
                if ($endpoint->url === $url) {
                    return [
                        'status' => 'error',
                        'message' => 'Webhook URL already exists.',
                        'webhook_id' => $endpoint->id,
                        'url' => $endpoint->url,
                    ];
                }
            }
			if($type==2){
				$defaultEvents = [
					"charge.refund.updated",
					"charge.dispute.created",
					"customer.subscription.created",
					"customer.subscription.pending_update_expired",
					"customer.subscription.pending_update_applied",
					"customer.subscription.deleted",
					"invoice.paid",
					"invoice.payment_failed",
					"invoice.payment_action_required",
					"checkout.session.completed",
					"payment_intent.succeeded"
				];
			}else{
				$defaultEvents = [
					"payment_intent.amount_capturable_updated",
					"payment_intent.canceled",
					"payment_intent.created",
					"payment_intent.partially_funded",
					"payment_intent.payment_failed",
					"payment_intent.processing",
					"payment_intent.requires_action",
					"payment_intent.succeeded"
				];
				
			}
            $webhook = WebhookEndpoint::create([
                'url' => $url,
                'enabled_events' => $events ?: $defaultEvents,
            ]);

            return [
                'status' => 'success',
                'message' => 'Webhook created.',
                'webhook_id' => $webhook->id,
                'url' => $webhook->url,
                'secret' => $webhook->secret ?? null,
            ];

        } catch (\Exception $e) { 
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取所有 Webhooks
     */
    public function listWebhooks(): array
    {
        try {
            $webhooks = WebhookEndpoint::all();
            return [
                'status' => 'success',
                'data' => $webhooks->data,
            ];
        } catch (\Exception $e) {
            Log::error('获取 Webhook 列表失败: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 查看指定 Webhook
     */
    public function getWebhook(string $id): array
    {
        try {
            $webhook = WebhookEndpoint::retrieve($id);
            return [
                'status' => 'success',
                'data' => $webhook,
            ];
        } catch (\Exception $e) {
            Log::error('获取 Webhook 失败: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 修改 Webhook（URL 或事件）
     */
    public function updateWebhook(string $id, ?string $newUrl = null, array $newEvents = []): array
    {
        try {
            $params = [];
            if ($newUrl) $params['url'] = $newUrl;
            if (!empty($newEvents)) $params['enabled_events'] = $newEvents;

            if (empty($params)) {
                return [
                    'status' => 'error',
                    'message' => 'No parameters provided to update.',
                ];
            }

            $updated = WebhookEndpoint::update($id, $params);

            return [
                'status' => 'success',
                'message' => 'Webhook updated.',
                'data' => $updated,
            ];
        } catch (\Exception $e) {
            Log::error('更新 Webhook 失败: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 删除 Webhook
     */
    public function deleteWebhook(string $id): array
    {
        try {
            $deleted = WebhookEndpoint::retrieve($id)->delete();

            return [
                'status' => $deleted->deleted ? 'success' : 'error',
                'message' => $deleted->deleted ? 'Webhook deleted.' : 'Failed to delete webhook.',
            ];
        } catch (\Exception $e) {
            Log::error('删除 Webhook 失败: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}

 