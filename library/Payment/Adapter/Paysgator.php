<?php

/**
 * Paysgator Payment Gateway Adapter for FOSSBilling
 *
 * This adapter integrates Paysgator payment gateway with FOSSBilling.
 * It supports webhook notifications and uses API Key authentication.
 *
 * @copyright 2026 Paysgator
 * @license Apache-2.0
 */

class Payment_Adapter_Paysgator
{
    protected $di = null;

    public function __construct(private $config)
    {
    }

    public function setDi($di): void
    {
        $this->di = $di;
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept payments via Paysgator - M-Pesa, E-mola, Cards and more',
            'logo' => array(
                'logo' => 'Paysgator.png',
                'height' => '50px',
                'width' => '50px',
            ),
            'form' => [
                'api_key' => [
                    'text', [
                        'label' => 'API Key',
                        'description' => 'Enter your Paysgator API Key (Live or Test)',
                        'validators' => ['nonempty'],
                    ],
                ],
                'webhook_secret' => [
                    'text', [
                        'label' => 'Webhook Secret',
                        'description' => 'Optional: Enter your Paysgator Webhook Secret for signature verification',
                    ],
                ],
                'test_mode' => [
                    'radio', [
                        'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                        'label' => 'Test Mode',
                        'description' => 'Enable test mode for sandbox testing',
                    ],
                ],
            ],
        ];
    }

    /**
     * Gera o redirecionamento ou formulário HTML.
     * No caso da Paysgator, faremos a chamada de API e redirecionaremos o usuário.
     */
    public function getHtml(Api_Handler $api_admin, int $invoice_id, bool $subscription): string
    {
    
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->toApiArray($invoiceModel, true);

        $externalTxId = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', 'inv' . $invoice_id . time()), 0, 32);

       $data = [
           'amount' => (double)$invoiceService->getTotalWithTax($invoiceModel),
           'currency' => $invoice['currency'],
           'externalTransactionId' => $externalTxId,
           'fields' => ['name', 'email', 'phone', 'address'],
           'returnUrl' => $this->di['tools']->url('invoice/'),
           'metadata' => [
               'description' => 'Invoice #' . $invoice['serie'] . $invoice['nr'],
               'source' => 'FOSSBilling',
               'invoice_id' => $invoice_id,
               'client_email' => $invoice['client']['email'],
           ],
       ];

        $apiUrl = 'https://paysgator.com/api/v1/payment/create';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->config['api_key'],
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
            error_log('Paysgator API Error: Invalid JSON response');
            return 'Erro ao processar pagamento com Paysgator. Por favor, contate o suporte.';
        }
        curl_close($ch);

        if (isset($result['success']) && $result['success'] && isset($result['data']['checkoutUrl'])) {
            // Retorna um JavaScript para redirecionamento imediato
            return '<script type="text/javascript">window.location.href = "' . htmlspecialchars($result['data']['checkoutUrl'], ENT_QUOTES, 'UTF-8') . '";</script>';
        }

        error_log('Paysgator Payment Error: ' . $response);
        return 'Erro ao processar pagamento com Paysgator. Por favor, contate o suporte.';
    }

    /**
     * Processa o Webhook/IPN
     */
    public function processTransaction(Api_Handler $api_admin, int $id, array $data, int $gateway_id)
    {
        try {
            $rawPayload = file_get_contents('php://input');
            if ($rawPayload === false) {
                error_log('Paysgator Webhook Error: Failed to read input stream');
                return false;
            }
            $webhookData = json_decode($rawPayload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($webhookData)) {
                error_log('Paysgator Webhook Error: Invalid JSON payload');
                return false;
            }
            
            // Verificação de Assinatura
            $signature = $_SERVER['HTTP_X_PAYSGATOR_SIGNATURE'] ?? '';
            $webhookSecret = $this->config['webhook_secret'] ?? '';

            if (!empty($webhookSecret)) {
                $expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
                if (!hash_equals($expectedSignature, $signature)) {
                    throw new Exception('Invalid webhook signature');
                }
            }

            if (($webhookData['event'] ?? '') !== 'payment.success') {
                return false;
            }

            $eventData = $webhookData['data'];
            
            // Carregar modelos necessários via DI
            $tx = $this->di['db']->getExistingModelById('Transaction', $id);
            $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);
            $gateway = $this->di['db']->load('PayGateway', $gateway_id);
            
            $clientService = $this->di['mod_service']('Client');
            $invoiceService = $this->di['mod_service']('Invoice');
            
            $client = $clientService->get(['id' => $invoice->client_id]);
            $amount = $eventData['amount'] ?? $invoiceService->getTotalWithTax($invoice);

            // Adicionar fundos e marcar como pago
            $tx_desc = $gateway->title . ' Webhook No: ' . ($eventData['transactionId'] ?? $id);
            $clientService->addFunds($client, $amount, $tx_desc, []);
            $invoiceService->markAsPaid($invoice, true, true);

            // Atualizar status da transação no FOSSBilling
            $tx->status = 'succeeded';
            $tx->txn_id = $eventData['transactionId'] ?? $tx->txn_id;
            $tx->amount = $amount;
            $tx->currency = $invoice->currency;
            $tx->note = $tx_desc;
            $tx->updated_at = date('Y-m-d H:i:s');

            $storeResult = $this->di['db']->store($tx);
            if (!$storeResult) {
                error_log('Paysgator Error: Failed to store transaction record');
                return false;
            }
            return $storeResult;

        } catch (Exception $e) {
            error_log('Paysgator Error: ' . $e->getMessage());
            return false;
        }
    }
}