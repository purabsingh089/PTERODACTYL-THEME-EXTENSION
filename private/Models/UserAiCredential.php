<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user OpenAI-compatible credentials for the AI Server Builder.
 * The API key is stored encrypted; it is never sent to the browser.
 *
 * @property int $id
 * @property int $user_id
 * @property string $base_url
 * @property string $api_key_encrypted
 * @property string $model
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserAiCredential extends Model
{
    protected $table = 'primus_user_ai_credentials';

    protected $fillable = [
        'user_id',
        'base_url',
        'api_key_encrypted',
        'model',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    public function apiKey(): string
    {
        $raw = (string) $this->api_key_encrypted;
        if ($raw === '') {
            return '';
        }

        try {
            return (string) decrypt($raw);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function setApiKey(string $plain): void
    {
        $this->api_key_encrypted = encrypt($plain);
    }
}
