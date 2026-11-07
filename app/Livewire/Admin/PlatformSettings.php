<?php

namespace App\Livewire\Admin;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres de la plateforme : clé API et modèle Claude pour la lecture des bordereaux,
 * avec la consommation des 30 derniers jours. La clé n'est jamais réaffichée en clair.
 */
#[Layout('components.layouts.app')]
#[Title('Paramètres - PharmaPOS Admin')]
class PlatformSettings extends Component
{
    /** Modèle => [libellé, prix entrée $/M tokens, prix sortie $/M tokens] */
    public const MODELS = [
        'claude-opus-5-5' => ['Claude Opus 5.5 - le plus fiable (recommandé)', 4.0, 20.0],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5 - bon compromis', 2.0, 10.0],
        'claude-haiku-5-5' => ['Claude Haiku 5.5 - le moins cher', 0.10, 0.50],
    ];

    public string $apiKey = '';

    public string $model = '';

    public ?string $message = null;

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $this->model = PlatformSetting::anthropicModel();
    }

    public function save(): void
    {
        $this->validate([
            'apiKey' => ['nullable', 'string', 'starts_with:sk-ant-', 'max:300'],
            'model' => ['required', 'in:'.implode(',', array_keys(self::MODELS))],
        ], ['apiKey.starts_with' => 'Une clé API Claude commence par « sk-ant- ».'], ['apiKey' => 'clé API']);

        $changes = ['model' => $this->model];
        if ($this->apiKey !== '') {
            PlatformSetting::put(PlatformSetting::ANTHROPIC_KEY, $this->apiKey);
            $changes['key'] = '…'.substr($this->apiKey, -4);
        }
        PlatformSetting::put(PlatformSetting::ANTHROPIC_MODEL, $this->model);
        $this->log('platform.settings_updated', $changes);

        $this->apiKey = '';
        $this->error = null;
        $this->message = 'Paramètres enregistrés.';
    }

    /** Retire la clé enregistrée ici (celle du .env du serveur reprend effet si elle existe). */
    public function removeKey(): void
    {
        PlatformSetting::put(PlatformSetting::ANTHROPIC_KEY, null);
        $this->log('platform.settings_updated', ['key' => 'supprimée']);
        $this->message = 'Clé supprimée.';
    }

    /** Vérifie la clé et l'accès au modèle choisi, sans consommer de tokens. */
    public function test(): void
    {
        $this->message = $this->error = null;
        $key = $this->apiKey ?: PlatformSetting::anthropicKey();
        if (! $key) {
            $this->error = 'Aucune clé API renseignée.';

            return;
        }
        try {
            $info = (new Client(apiKey: $key, requestOptions: ['maxRetries' => 0]))->models->retrieve($this->model);
            $this->message = "Connexion réussie : {$info->displayName} est accessible avec cette clé.";
        } catch (AuthenticationException) {
            $this->error = 'Échec de la connexion : clé refusée.';
        } catch (NotFoundException) {
            $this->error = 'Clé valide, mais ce modèle n\'est pas accessible avec elle.';
        } catch (AnthropicException $e) {
            $this->error = 'Échec de la connexion : '.$e->getMessage();
        }
    }

    private function log(string $action, array $values): void
    {
        // Journal applicatif : le super-administrateur n'appartient à aucune pharmacie
        logger()->info($action, ['user' => auth()->user()->username] + $values);
    }

    public function render()
    {
        $key = PlatformSetting::anthropicKey();
        $reads = AuditLog::where('action', 'delivery_note.read')->where('created_at', '>=', now()->subDays(30))->get(['pharmacy_id', 'new_values']);
        $cost = $reads->sum(function ($r) {
            $v = $r->new_values;
            $price = self::MODELS[preg_replace('/-\d{8}$/', '', (string) ($v['model'] ?? ''))] ?? self::MODELS['claude-opus-5-5'];

            return (($v['input_tokens'] ?? 0) * $price[1] + ($v['output_tokens'] ?? 0) * $price[2]) / 1_000_000;
        });

        return view('livewire.admin.platform-settings', [
            'keyHint' => $key ? 'sk-ant-…'.substr($key, -4) : null,
            'keySource' => PlatformSetting::get(PlatformSetting::ANTHROPIC_KEY) ? 'console' : ($key ? 'env' : null),
            'usage' => [
                'reads' => $reads->count(),
                'pages' => $reads->sum(fn ($r) => $r->new_values['pages'] ?? 0),
                'pharmacies' => $reads->pluck('pharmacy_id')->unique()->count(),
                'cost' => $cost,
            ],
        ]);
    }
}
