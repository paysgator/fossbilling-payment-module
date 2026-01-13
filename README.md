<div align="center">
  <img src="https://paysgator.com/paysgator_logo.png" alt="Paysgator" width="300"/>
</div>

# Paysgator FOSSBilling Payment Gateway


This module allows you to accept payments via Paysgator in FOSSBilling.

## Installation

1. Download or clone this repository.

2. Copy the `library` folder to your FOSSBilling root directory. It should merge with your existing `library` folder.
   
   Final structure should look like:
   ```
   /path/to/fossbilling/
   └── library/
       └── Payment/
           └── Adapter/
               ├── Paysgator.php
               └── Paysgator.png
   ```

3. Log in to your FOSSBilling Admin Area.

4. Go to **System > Payment Gateways**.

5. Find **Paysgator** in the list of available gateways and click **Activate**.

6. Configure the following settings:
   - **API Key**: Required. Your Paysgator API key (obtainable from your Paysgator Dashboard).
   - **Webhook Secret**: Optional but recommended. Your Paysgator webhook secret for HMAC signature verification.
   - **Test Mode**: Enable for sandbox testing.

7. Click **Save**.

## Configuration

- **API Key**: Required. Your Paysgator API key (Live or Test).
- **Webhook Secret**: Optional. Your Paysgator webhook secret for HMAC-SHA256 signature verification.
- **Test Mode**: Enable for sandbox testing.

## Webhooks

Paysgator will send webhooks to notify FOSSBilling of payment events.

**Webhook URL**: `https://your-fossbilling-domain.com/ipn.php?gateway_id=X`

(Replace `X` with your Paysgator gateway ID from FOSSBilling)

Configure this URL in your Paysgator Dashboard under Webhooks settings.

### Supported Events
- `payment.success` - Automatically marks invoices as paid
- Other events are logged but not processed

### Security
The module supports HMAC-SHA256 signature verification. To enable:
1. Get your Webhook Secret from Paysgator Dashboard
2. Enter it in the **Webhook Secret** field in FOSSBilling gateway configuration

## Transaction ID Format

The module uses a sanitized `externalTransactionId` format: `inv-{invoiceId}` (max 15 characters, alphanumeric with dash/underscore only).

## Requirements

- FOSSBilling 0.5.0 or higher
- PHP 7.4 or higher
- cURL extension enabled

## Support

For issues or questions, contact Paysgator support at https://paysgator.com

## License

Apache-2.0
