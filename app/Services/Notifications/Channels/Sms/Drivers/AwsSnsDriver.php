<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Sms\Drivers;

use App\Services\Notifications\Channels\Sms\SmsDriver;
use Aws\Credentials\Credentials;
use Aws\PinpointSMSVoiceV2\PinpointSMSVoiceV2Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AWS Pinpoint SMS Voice V2 driver — sends messages via AWS End User Messaging SMS.
 *
 * Requires driver_config (manual credentials):
 *   - access_key_id     (string) AWS Access Key ID
 *   - secret_access_key (string) AWS Secret Access Key
 *   - region            (string) AWS Region (default: eu-north-1)
 *   - pool_id           (string) Optional Pinpoint Pool ID (e.g. pool-abc123)
 *   - sender_id         (string) Optional Sender ID (max 11 chars, alphanumeric)
 *   - message_type      (string) TRANSACTIONAL | PROMOTIONAL (default: TRANSACTIONAL)
 *
 * Requires driver_config (IAM Role / EC2 Instance Profile):
 *   - use_iam_role      (bool)   true to use IAM Role (no keys needed)
 *   - region            (string) AWS Region (default: eu-north-1)
 *   - pool_id           (string) Optional Pinpoint Pool ID
 *   - sender_id         (string) Optional Sender ID
 *   - message_type      (string) TRANSACTIONAL | PROMOTIONAL (default: TRANSACTIONAL)
 */
final class AwsSnsDriver implements SmsDriver
{
    private const DEFAULT_REGION = 'eu-north-1';
    private const DEFAULT_MESSAGE_TYPE = 'TRANSACTIONAL';

    public function send(string $to, string $message, array $context = []): void
    {
        $config = config('sms.driver_config.' . config('sms.active_driver_key', 'aws_sns'), []);

        $region      = $config['region'] ?? self::DEFAULT_REGION;
        $poolId      = $config['pool_id'] ?? null;
        $senderId    = $config['sender_id'] ?? null;
        $messageType = strtoupper($config['message_type'] ?? self::DEFAULT_MESSAGE_TYPE);
        $useIamRole  = $config['use_iam_role'] ?? false;

        $accessKeyId     = $config['access_key_id'] ?? null;
        $secretAccessKey = $config['secret_access_key'] ?? null;

        Log::info('SMS_AWS_PINPOINT_START', [
            'to'          => $to,
            'region'        => $region,
            'pool_id'       => $poolId,
            'use_iam_role'  => $useIamRole,
            'has_keys'      => !empty($accessKeyId) && !empty($secretAccessKey),
            'context'       => $context,
        ]);

        $clientArgs = [
            'region'  => $region,
            'version' => 'latest',
        ];

        if (! $useIamRole) {
            if (empty($accessKeyId) || empty($secretAccessKey)) {
                Log::warning('SMS_AWS_PINPOINT_MISSING_KEYS', [
                    'to'      => $to,
                    'context' => $context,
                ]);
                throw new \RuntimeException('AWS Pinpoint SMS configuration missing: access_key_id or secret_access_key (or set use_iam_role:true)');
            }
            $clientArgs['credentials'] = new Credentials($accessKeyId, $secretAccessKey);
        }

        try {
            $client = new PinpointSMSVoiceV2Client($clientArgs);

            $args = [
                'DestinationPhoneNumber' => $to,
                'MessageBody'            => $message,
                'MessageType'            => $messageType,
            ];

            if ($poolId !== null && $poolId !== '') {
                $args['PoolId'] = $poolId;
            } elseif ($senderId !== null && $senderId !== '') {
                $args['OriginationIdentity'] = $senderId;
            }

            if ($senderId !== null && $senderId !== '') {
                $args['SenderId'] = $senderId;
            }

            $result = $client->sendTextMessage($args);

            $messageId = $result['MessageId'] ?? null;

            Log::channel('otp')->info('SMS_AWS_PINPOINT_SENT', [
                'to'         => $to,
                'messageId'  => $messageId,
                'poolId'     => $poolId,
                'context'    => $context,
                'sent_at'    => Carbon::now()->toIso8601String(),
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            Log::channel('otp')->error('SMS_AWS_PINPOINT_FAILED', [
                'to'         => $to,
                'errorCode'  => $e->getAwsErrorCode(),
                'errorMsg'   => $e->getAwsErrorMessage(),
                'statusCode' => $e->getStatusCode(),
                'context'    => $context,
                'sent_at'    => Carbon::now()->toIso8601String(),
            ]);

            throw new \RuntimeException('AWS Pinpoint SMS error: ' . $e->getAwsErrorMessage());
        } catch (\Throwable $e) {
            Log::channel('otp')->error('SMS_AWS_PINPOINT_EXCEPTION', [
                'to'        => $to,
                'error'     => $e->getMessage(),
                'class'     => get_class($e),
                'context'   => $context,
                'sent_at'   => Carbon::now()->toIso8601String(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch temporary credentials from EC2 Instance Metadata Service (IMDS v2).
     * Kept for backward compatibility when not using the AWS SDK auto-discovery.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function getIamCredentials(): array
    {
        $token = $this->getMetadataToken();
        $roleName = $this->getIamRoleName($token);

        $response = Http::withHeaders([
            'X-aws-ec2-metadata-token' => $token,
        ])->get("http://169.254.169.254/latest/meta-data/iam/security-credentials/{$roleName}");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch IAM credentials from EC2 metadata: ' . $response->body());
        }

        $data = $response->json();

        if (empty($data['AccessKeyId']) || empty($data['SecretAccessKey'])) {
            throw new \RuntimeException('Invalid IAM credentials response from EC2 metadata');
        }

        return $data;
    }

    private function getIamRoleName(string $token): string
    {
        $response = Http::withHeaders([
            'X-aws-ec2-metadata-token' => $token,
        ])->get('http://169.254.169.254/latest/meta-data/iam/security-credentials/');

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch IAM role name from EC2 metadata: ' . $response->body());
        }

        $roleName = trim($response->body());

        if ($roleName === '') {
            throw new \RuntimeException('No IAM role attached to this EC2 instance');
        }

        return $roleName;
    }

    private function getMetadataToken(): string
    {
        $response = Http::withHeaders([
            'X-aws-ec2-metadata-token-ttl-seconds' => '21600',
        ])->put('http://169.254.169.254/latest/api/token');

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch EC2 metadata token: ' . $response->body());
        }

        return trim($response->body());
    }
}
