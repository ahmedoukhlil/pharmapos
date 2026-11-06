<?php

namespace App\Services\DeliveryNotes;

use App\Models\AuditLog;
use App\Models\Presentation;
use App\Models\Supplier;
use App\Models\SupplierProductAlias;
use App\Models\User;
use App\Support\ExpiryDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Bordereau transcrit → lignes de réception proposées. Rapprochement avec le catalogue :
 * code-barres / SKU, puis désignation déjà associée chez ce fournisseur, puis nom + dosage + conditionnement.
 * Rien n'est enregistré ici : l'utilisateur contrôle le tableau avant de l'ajouter à la réception.
 */
class DeliveryNoteReader
{
    public function __construct(private DeliveryNoteExtractor $extractor) {}

    /**
     * Le fournisseur est toujours celui choisi sur la réception, jamais celui imprimé sur le bordereau.
     * @param  list<array{path: string, mime: string}>  $files
     * @return array{number: string, date: string, total: float, computed_total: float, lines: list<array>}
     */
    public function read(User $user, Supplier $supplier, array $files): array
    {
        abort_unless($user->hasPermission('purchase.manage') && $supplier->pharmacy_id === $user->pharmacy_id, 403);

        $data = $this->extractor->extract($files);

        AuditLog::create([
            'pharmacy_id' => $user->pharmacy_id,
            'user_id' => $user->id,
            'action' => 'delivery_note.read',
            'auditable_type' => 'supplier',
            'auditable_id' => $supplier->id,
            'new_values' => ['pages' => count($files), 'lines' => count($data['lines'])] + $data['usage'],
        ]);

        $lines = array_map(fn (array $l) => $this->match($user->pharmacy_id, $supplier->id, $l), $data['lines']);

        return [
            'number' => trim((string) $data['delivery_note_number']),
            'date' => trim((string) $data['date']),
            'total' => (float) $data['total'],
            'computed_total' => round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines)), 2),
            'lines' => $lines,
        ];
    }

    /** Mémorise les associations confirmées pour les prochains bordereaux de ce fournisseur. */
    public function learn(User $user, string $supplierId, string $label, string $presentationId): void
    {
        $key = SupplierProductAlias::key($label);
        if ($key === '' || ! Presentation::where('pharmacy_id', $user->pharmacy_id)->whereKey($presentationId)->exists()) {
            return;
        }
        SupplierProductAlias::updateOrCreate(
            ['pharmacy_id' => $user->pharmacy_id, 'supplier_id' => $supplierId, 'label_key' => $key],
            ['label' => Str::limit($label, 250, ''), 'presentation_id' => $presentationId],
        );
    }

    private function match(string $pharmacyId, string $supplierId, array $l): array
    {
        $label = trim((string) $l['designation']);
        $code = strtoupper(preg_replace('/\s+/', '', (string) $l['code']));
        $line = [
            'designation' => $label,
            'code' => $code,
            'lot' => trim((string) $l['lot']),
            'expiry' => trim((string) $l['expiry']),
            'quantity' => (float) $l['quantity'],
            'unit_price' => (float) $l['unit_price'],
            'line_total' => (float) $l['line_total'],
            'presentation_id' => '',
            'source' => '',
            'candidates' => [],
            'warnings' => [],
        ];

        $presentations = Presentation::where('pharmacy_id', $pharmacyId)->where('status', 'active')->with('medicine');

        if ($code !== '') {
            $byCode = (clone $presentations)->where(fn ($q) => $q->where('sku', $code)
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $code)->where('is_active', true)))->first();
            if ($byCode) {
                [$line['presentation_id'], $line['source']] = [$byCode->id, 'code'];
            }
        }

        if (! $line['presentation_id'] && $label !== '') {
            $alias = SupplierProductAlias::where('pharmacy_id', $pharmacyId)->where('supplier_id', $supplierId)
                ->where('label_key', SupplierProductAlias::key($label))->first();
            if ($alias) {
                [$line['presentation_id'], $line['source']] = [$alias->presentation_id, 'alias'];
            }
        }

        if (! $line['presentation_id'] && $label !== '') {
            $line['candidates'] = $this->candidates($presentations, $label);
            $best = $line['candidates'][0] ?? null;
            $second = $line['candidates'][1] ?? null;
            // Proposé seulement si le nom, le dosage et le conditionnement concordent, sans ex aequo
            if ($best && $best['score'] >= 3 && (! $second || $second['score'] < $best['score'])) {
                [$line['presentation_id'], $line['source']] = [$best['id'], 'name'];
            }
        }

        return $this->check($pharmacyId, $line);
    }

    /** @return list<array{id: string, label: string, score: int}> */
    private function candidates($presentations, string $label): array
    {
        $key = SupplierProductAlias::key($label);
        $words = array_values(array_filter(explode(' ', $key), fn ($w) => strlen($w) >= 3 && ! ctype_digit($w)));
        if (! $words) {
            return [];
        }
        preg_match_all('/\d+(?:[.,]\d+)?/', $label, $m);
        $numbers = array_map(fn ($n) => (float) str_replace(',', '.', $n), $m[0]);

        return (clone $presentations)
            ->whereHas('medicine', fn ($q) => $q->where('brand_name', 'like', $words[0].'%')->orWhere('inn', 'like', $words[0].'%'))
            ->limit(20)->get()
            ->map(function (Presentation $p) use ($key, $numbers) {
                $name = SupplierProductAlias::key($p->medicine->brand_name);
                $inn = SupplierProductAlias::key((string) $p->medicine->inn);
                // Les grossistes écrivent souvent la DCI (METFORMINE) plutôt que le nom commercial (Glucophage)
                $score = str_starts_with($key, $name) || ($inn !== '' && str_starts_with($key, $inn)) ? 2
                    : (str_contains($key, explode(' ', $name)[0]) ? 1 : 0);
                preg_match('/\d+(?:[.,]\d+)?/', (string) $p->medicine->strength, $dose);
                if ($dose && in_array((float) str_replace(',', '.', $dose[0]), $numbers, true)) {
                    $score++;
                }
                if ($p->units_per_pack > 1 && in_array((float) $p->units_per_pack, $numbers, true)) {
                    $score++;
                }

                return ['id' => $p->id, 'label' => trim("{$p->medicine->brand_name} {$p->medicine->strength} — {$p->label}"), 'score' => $score];
            })
            ->sortByDesc('score')->values()->take(5)->all();
    }

    private function check(string $pharmacyId, array $line): array
    {
        if ($line['quantity'] <= 0) {
            $line['warnings'][] = 'Quantité absente.';
        }
        if ($line['line_total'] > 0 && $line['unit_price'] > 0 && abs($line['quantity'] * $line['unit_price'] - $line['line_total']) > 1) {
            $line['warnings'][] = 'Quantité × prix ≠ montant de la ligne.';
        }
        if ($line['expiry'] !== '') {
            $expiry = ExpiryDate::parse($line['expiry'], $pharmacyId);
            if (! $expiry) {
                $line['warnings'][] = 'Péremption illisible.';
            } elseif ($expiry->isPast()) {
                $line['warnings'][] = 'Produit déjà périmé.';
            } elseif ($expiry->lt(now()->addMonths(6))) {
                $line['warnings'][] = 'Péremption à moins de 6 mois.';
            }
        }
        if ($line['presentation_id']) {
            $last = (float) Presentation::whereKey($line['presentation_id'])->value('purchase_price');
            if ($last > 0 && $line['unit_price'] > 0 && abs($line['unit_price'] - $last) / $last > 0.2) {
                $line['warnings'][] = 'Prix d\'achat différent de plus de 20 % du dernier prix ('.number_format($last, 2, ',', ' ').').';
            }
        }

        $line['status'] = match (true) {
            ! $line['presentation_id'] => 'unknown',
            $line['source'] === 'name' || $line['warnings'] !== [] => 'check',
            default => 'ok',
        };

        return $line;
    }
}
