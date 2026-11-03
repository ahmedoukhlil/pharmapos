<?php

namespace Tests\Feature;

use App\Livewire\Purchasing\ReceiptEditor;
use App\Models\AuditLog;
use App\Models\Presentation;
use App\Models\Supplier;
use App\Models\SupplierProductAlias;
use App\Models\User;
use App\Services\DeliveryNotes\ClaudeDeliveryNoteExtractor;
use App\Services\DeliveryNotes\DeliveryNoteException;
use App\Services\DeliveryNotes\DeliveryNoteExtractor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lecture des bordereaux : la transcription (Claude) est simulée ; on vérifie le rapprochement
 * avec le catalogue, les contrôles, le tableau à valider et l'apprentissage par fournisseur.
 */
class DeliveryNoteReaderTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function fakeNote(array $lines, float $total = 0): void
    {
        $this->app->instance(DeliveryNoteExtractor::class, new class($lines, $total) implements DeliveryNoteExtractor
        {
            public function __construct(private array $lines, private float $total) {}

            public function extract(array $files): array
            {
                return ['delivery_note_number' => 'BL-2026-0457', 'supplier_name' => 'Grossiste', 'date' => '03/11/2026', 'total' => $this->total,
                    'lines' => $this->lines, 'usage' => ['input_tokens' => 3000, 'output_tokens' => 800, 'model' => 'test']];
            }
        });
    }

    private function line(string $designation, float $qty, float $price, string $code = '', string $lot = 'L1', string $expiry = '08/2028'): array
    {
        return ['designation' => $designation, 'code' => $code, 'lot' => $lot, 'expiry' => $expiry, 'quantity' => $qty, 'unit_price' => $price, 'line_total' => $qty * $price];
    }

    public function test_note_is_matched_checked_then_added_and_learned(): void
    {
        $this->fakeNote([
            $this->line('PARACETAMOL DEMO 500MG B/16', 10, 120, '2000000000015'),       // code-barres
            $this->line('IBUPROFENE DEMO 400 MG BTE 20', 5, 300),                        // nom + dosage + conditionnement, prix +100 %
            $this->line('PRODUIT INCONNU 50MG', 2, 80, '', 'X9', '01/2020'),            // inconnu et périmé
        ], 2600);
        $this->actingAs(User::where('username', 'storekeeper')->firstOrFail());

        $page = UploadedFile::fake()->image('bl-page1.jpg');
        $c = Livewire::test(ReceiptEditor::class)->set('noteFiles', [$page])->call('readNote')->assertHasNoErrors()
            ->assertSee('Bordereau lu')->assertSee('PRODUIT INCONNU 50MG')->assertSee('Produit déjà périmé')->assertSee('plus de 20 %');

        $note = $c->get('note');
        $this->assertSame(['ok', 'check', 'unknown'], array_column($note['lines'], 'status'));
        $this->assertSame(Presentation::where('sku', 'IBU400-20')->value('id'), $note['lines'][1]['presentation_id']);
        $this->assertTrue(AuditLog::where('action', 'delivery_note.read')->exists());

        // Une ligne sans produit bloque l'ajout ; on la retire
        $c->call('applyNote')->assertSee('sans produit')->call('removeNoteLine', 2)->call('applyNote')
            ->assertSet('note', null)->assertSet('deliveryNoteRef', 'BL-2026-0457');
        $lines = $c->get('lines');
        $this->assertCount(2, $lines);
        $this->assertSame(['10', '120', 'L1', '08/2028'], [$lines[0]['quantity_packs'], $lines[0]['unit_price'], $lines[0]['lot_number'], $lines[0]['expiry']]);

        // Bordereau suivant du même fournisseur : la désignation est reconnue directement
        $this->assertSame(2, SupplierProductAlias::count());
        $this->fakeNote([$this->line('Ibuprofene demo 400 mg bte 20', 3, 150)]);
        $c = Livewire::test(ReceiptEditor::class)->set('noteFiles', [$page])->call('readNote');
        $this->assertSame(['ok', 'alias'], [$c->get('note')['lines'][0]['status'], $c->get('note')['lines'][0]['source']]);
    }

    public function test_unknown_line_can_be_assigned_and_errors_are_shown(): void
    {
        $this->fakeNote([$this->line('DOLIPRANE 1000', 4, 120)]);
        $this->actingAs(User::where('username', 'storekeeper')->firstOrFail());
        $c = Livewire::test(ReceiptEditor::class)->set('noteFiles', [UploadedFile::fake()->create('bl.pdf', 200, 'application/pdf')])->call('readNote');
        $this->assertSame('unknown', $c->get('note')['lines'][0]['status']);

        $id = Presentation::where('sku', 'AMOX500-12')->value('id');
        $c->set('note.lines.0.presentation_id', $id)->call('applyNote');
        $this->assertSame($id, $c->get('lines')[0]['presentation_id']);

        // Mauvais type de fichier ; clé absente
        Livewire::test(ReceiptEditor::class)->set('noteFiles', [UploadedFile::fake()->create('bl.docx', 10)])->call('readNote')->assertHasErrors('noteFiles.0');
        config(['services.anthropic.key' => null]);
        $this->expectException(DeliveryNoteException::class);
        app(ClaudeDeliveryNoteExtractor::class)->extract([]);
    }

    public function test_line_written_with_the_inn_is_matched(): void
    {
        $this->fakeNote([$this->line('AMOXICILLINE 500 MG B/12 - LOT: A1', 3, 300)]);
        $this->actingAs(User::where('username', 'storekeeper')->firstOrFail());
        $line = Livewire::test(ReceiptEditor::class)->set('noteFiles', [UploadedFile::fake()->image('bl.jpg')])->call('readNote')->get('note')['lines'][0];

        $this->assertSame(['check', 'name'], [$line['status'], $line['source']]);
        $this->assertSame(Presentation::where('sku', 'AMOX500-12')->value('id'), $line['presentation_id']);
    }

    public function test_cashier_cannot_read_notes(): void
    {
        $this->fakeNote([]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\DeliveryNotes\DeliveryNoteReader::class)->read(User::where('username', 'cashier')->firstOrFail(), Supplier::firstOrFail(), []);
    }
}
