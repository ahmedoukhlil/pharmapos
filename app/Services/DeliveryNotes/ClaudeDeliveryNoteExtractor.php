<?php

namespace App\Services\DeliveryNotes;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\PlatformSetting;

/**
 * Transcription d'un bordereau par l'API Claude : toutes les pages dans une seule requête,
 * réponse contrainte par un schéma JSON. Claude recopie ; l'association au catalogue est faite ensuite.
 */
class ClaudeDeliveryNoteExtractor implements DeliveryNoteExtractor
{
    /** Côté le plus long d'une photo envoyée : au-delà, l'API réduit l'image sans gain de lecture. */
    private const MAX_IMAGE_SIDE = 1568;

    private const SYSTEM = <<<'TXT'
        Tu transcris des bordereaux de livraison (bons de livraison) de grossistes pharmaceutiques en Mauritanie.
        Les pages fournies forment un seul bordereau, dans l'ordre. Recopie fidèlement ce qui est imprimé, sans rien deviner ni corriger :
        - une ligne de produit du bordereau = une entrée de "lines", dans l'ordre du document ; ignore les lignes de sous-total, report, remise ou TVA ;
        - "designation" : le libellé exactement tel qu'imprimé (nom, dosage, forme, conditionnement) ;
        - "code" : code-barres, CIP ou référence article s'il est imprimé sur la ligne, sinon chaîne vide ;
        - "lot" et "expiry" : numéro de lot et date de péremption tels qu'imprimés (ex. 08/2027), sinon chaîne vide ;
        - "quantity" : quantité livrée (en boîtes / unités de vente du bordereau) ; "unit_price" : prix unitaire ; "line_total" : montant de la ligne ; 0 si absent ;
        - les montants sont en MRU ; convertis les nombres au format décimal avec un point (1 250,50 → 1250.5) ;
        - "delivery_note_number", "date" : en-tête du bordereau, chaîne vide si absent (le fournisseur est déjà connu : ne le lis pas) ; "total" : total général du bordereau, 0 si absent.
        Si un champ est illisible, laisse-le vide plutôt que d'inventer.
        TXT;

    public function extract(array $files): array
    {
        $key = PlatformSetting::anthropicKey();
        if (! $key) {
            throw new DeliveryNoteException('Lecture automatique non configurée : la clé API Claude n\'est pas renseignée (console admin → Paramètres).');
        }
        $model = PlatformSetting::anthropicModel();

        $content = array_map(fn (array $f) => $this->block($f), $files);
        $content[] = ['type' => 'text', 'text' => 'Transcris ce bordereau de livraison ('.count($files).' fichier(s)).'];

        try {
            $message = (new Client(apiKey: $key))->beta->messages->create(
                model: $model,
                maxTokens: 16000,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
                // Si le modèle décline pour raison de politique, l'API relance sur le modèle de repli par défaut
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException) {
            throw new DeliveryNoteException('Clé API Claude refusée : vérifiez-la dans la console admin → Paramètres.');
        } catch (RateLimitException) {
            throw new DeliveryNoteException('Service de lecture momentanément saturé : réessayez dans une minute.');
        } catch (APIStatusException $e) {
            report($e);
            throw new DeliveryNoteException('Le service de lecture a refusé le document (fichier trop lourd ou illisible ?).');
        } catch (APIConnectionException $e) {
            report($e);
            throw new DeliveryNoteException('Service de lecture injoignable : vérifiez la connexion du serveur.');
        }

        if ($message->stopReason === 'refusal') {
            throw new DeliveryNoteException('Le document n\'a pas pu être lu automatiquement : saisissez-le manuellement.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new DeliveryNoteException('Bordereau trop long pour une lecture : envoyez-le en plusieurs fois.');
        }

        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = json_decode($block->text, true);
                break;
            }
        }
        if (! is_array($json) || ! isset($json['lines'])) {
            throw new DeliveryNoteException('Réponse de lecture inattendue : réessayez.');
        }

        return $json + ['usage' => [
            'input_tokens' => $message->usage->inputTokens,
            'output_tokens' => $message->usage->outputTokens,
            'model' => $message->model,
        ]];
    }

    /** @param array{path: string, mime: string} $file */
    private function block(array $file): array
    {
        if ($file['mime'] === 'application/pdf') {
            return ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode(file_get_contents($file['path']))]];
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($this->jpeg($file['path']))]];
    }

    /** Photo de téléphone réduite et réencodée en JPEG (poids de la requête, orientation EXIF). */
    private function jpeg(string $path): string
    {
        $img = @imagecreatefromstring(file_get_contents($path));
        if (! $img) {
            throw new DeliveryNoteException('Image illisible : envoyez une photo JPEG ou PNG.');
        }
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $img = match ((int) $orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };

        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_IMAGE_SIDE / max($w, $h));
        if ($scale < 1) {
            $img = imagescale($img, (int) round($w * $scale), (int) round($h * $scale));
        }

        ob_start();
        imagejpeg($img, null, 85);

        return ob_get_clean();
    }

    private static function schema(): array
    {
        $str = ['type' => 'string'];
        $num = ['type' => 'number'];

        return [
            'type' => 'object',
            'properties' => [
                'delivery_note_number' => $str,
                'date' => $str,
                'total' => $num,
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'designation' => $str, 'code' => $str, 'lot' => $str, 'expiry' => $str,
                            'quantity' => $num, 'unit_price' => $num, 'line_total' => $num,
                        ],
                        'required' => ['designation', 'code', 'lot', 'expiry', 'quantity', 'unit_price', 'line_total'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['delivery_note_number', 'date', 'total', 'lines'],
            'additionalProperties' => false,
        ];
    }
}
