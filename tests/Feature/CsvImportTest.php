<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ImportBatch;
use App\Models\Unit;
use App\Models\User;
use App\Services\CsvExpenseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->startOfDay());
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('Importação', ['*'], now()->addHours(8))->plainTextToken);
        Unit::create(['name' => 'Operações', 'company' => 'Gridmidia', 'slug' => 'operacoes']);
        Unit::create(['name' => 'Tecnologia', 'company' => 'Gridmidia', 'slug' => 'tecnologia']);
    }

    private function csv(array $rows, bool $bom = false): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, CsvExpenseImporter::HEADER, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return ($bom ? "\xEF\xBB\xBF" : '').$content;
    }

    private function row(array $overrides = []): array
    {
        return array_replace(['2026-10-09', 'Licença de software', 'Fornecedor', '100.01', 'BRL', 'operacoes:50|tecnologia:50'], $overrides);
    }

    private function upload(string $content)
    {
        return $this->postJson('/api/v1/imports', ['file' => UploadedFile::fake()->createWithContent('despesas.csv', $content)]);
    }

    public function test_partial_import_keeps_valid_rows_and_returns_a_persisted_audit_for_each_error(): void
    {
        $rows = [
            $this->row(),
            $this->row([4 => 'USD', 1 => 'Serviço internacional']),
            $this->row([3 => '1.001']),
            $this->row([5 => 'inexistente:100']),
            $this->row([5 => 'operacoes:50|operacoes:50']),
            $this->row([5 => 'operacoes:49.99|tecnologia:50']),
            $this->row([4 => 'EUR']),
            $this->row([0 => '2026-02-30']),
            ['apenas', 'duas colunas'],
        ];
        $response = $this->upload($this->csv($rows))->assertCreated()->assertJsonPath('data.total_rows', 9)
            ->assertJsonPath('data.imported_rows', 2)->assertJsonPath('data.failed_rows', 7)
            ->assertJsonCount(7, 'data.errors')->assertJsonPath('data.original_name', 'despesas.csv')
            ->assertJsonPath('data.user_id', $this->user->id);
        $this->assertSame([4, 5, 6, 7, 8, 9, 10], array_column($response->json('data.errors'), 'line'));
        foreach ($response->json('data.errors') as $error) {
            $this->assertNotEmpty($error['messages']);
        }
        $this->assertDatabaseCount('expenses', 2);
        $this->assertSame([2, 3], array_column($response->json('data.successful_rows'), 'line'));
        $this->assertSame(Expense::orderBy('id')->pluck('id')->all(), array_column($response->json('data.successful_rows'), 'expense_id'));
        $this->assertDatabaseCount('allocations', 4);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(10001, Expense::where('currency', 'BRL')->sole()->brl_amount_minor);
        $this->assertNull(Expense::where('currency', 'USD')->sole()->brl_amount_minor);
        Http::assertNothingSent();

        $this->getJson('/api/v1/imports/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.total_rows', 9)->assertJsonPath('data.failed_rows', 7);
    }

    public function test_bom_quoted_headers_semicolons_and_multiline_cells_are_parsed_correctly(): void
    {
        $description = "Licença; plano \"Enterprise\"\nRenovação anual";
        $content = $this->csv([$this->row([1 => $description]), $this->row([5 => 'operacoes:99'])]);
        $content = "\xEF\xBB\xBF".preg_replace('/^[^\n]+/', '"data";"descricao";"fornecedor";"valor";"moeda";"rateio"', $content, 1);
        $this->upload($content)->assertCreated()->assertJsonPath('data.imported_rows', 1)
            ->assertJsonPath('data.failed_rows', 1)->assertJsonPath('data.errors.0.line', 4);
        $this->assertSame($description, Expense::sole()->description);
    }

    public function test_blank_lines_are_skipped_without_losing_physical_error_line_numbers(): void
    {
        $content = implode(';', CsvExpenseImporter::HEADER)."\r\n\r\n";
        $content .= implode(';', $this->row())."\r\n\r\n";
        $content .= implode(';', $this->row([5 => 'operacoes:80']))."\r\n";
        $this->upload($content)->assertCreated()->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.imported_rows', 1)->assertJsonPath('data.failed_rows', 1)
            ->assertJsonPath('data.errors.0.line', 5);
    }

    public function test_more_than_one_thousand_records_is_rejected_before_any_database_mutation(): void
    {
        $this->upload($this->csv(array_fill(0, 1001, $this->row([4 => 'USD']))))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_exactly_one_thousand_records_are_all_accounted_for(): void
    {
        $this->upload($this->csv(array_fill(0, 1000, $this->row([3 => '0.01', 5 => 'operacoes:100']))))
            ->assertCreated()->assertJsonPath('data.total_rows', 1000)
            ->assertJsonPath('data.imported_rows', 1000)->assertJsonPath('data.failed_rows', 0);
        $this->assertDatabaseCount('expenses', 1000);
        $this->assertDatabaseCount('allocations', 1000);
        $this->assertSame(1000, (int) Expense::sum('brl_amount_minor'));
    }

    #[DataProvider('invalidDocuments')]
    public function test_invalid_document_structure_is_rejected_without_creating_a_batch(string $content): void
    {
        $this->upload($content)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('expenses', 0);
    }

    public static function invalidDocuments(): array
    {
        return [
            'empty' => [''],
            'wrong delimiter' => ["data,descricao,fornecedor,valor,moeda,rateio\n"],
            'missing header' => ["2026-10-09;Software;Fornecedor;10;BRL;operacoes:100\n"],
            'only header' => ["data;descricao;fornecedor;valor;moeda;rateio\n\n"],
            'wrong order' => ["descricao;data;fornecedor;valor;moeda;rateio\n"],
        ];
    }

    public function test_invalid_utf8_is_a_row_error_and_does_not_break_json_audit(): void
    {
        $this->upload($this->csv([$this->row([1 => "Licen\xE7a"]), $this->row()]))
            ->assertCreated()->assertJsonPath('data.total_rows', 2)->assertJsonPath('data.failed_rows', 1)
            ->assertJsonPath('data.imported_rows', 1)->assertJsonPath('data.errors.0.messages.0', 'Use codificação UTF-8.');
        $this->assertDatabaseCount('expenses', 1);
        $this->assertNotEmpty(ImportBatch::sole()->errors);
    }

    public function test_reupload_is_idempotent_and_source_is_not_exposed(): void
    {
        $content = $this->csv([$this->row(), $this->row([5 => 'operacoes:80'])]);
        $first = $this->upload($content)->assertCreated()->assertJsonPath('data.status', 'completed');
        $second = $this->upload($content)->assertCreated();
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $second->assertJsonPath('data.original_import_id', $first->json('data.id'))
            ->assertJsonPath('data.imported_rows', 0)->assertJsonPath('data.ignored_rows', 1)
            ->assertJsonPath('data.failed_rows', 1)->assertJsonCount(0, 'data.successful_rows');
        $this->assertSame(2, $second->json('data.imported_rows') + $second->json('data.ignored_rows') + $second->json('data.failed_rows'));
        $third = $this->upload($content)->assertCreated();
        $third->assertJsonPath('data.original_import_id', $first->json('data.id'))
            ->assertJsonPath('data.imported_rows', 0)->assertJsonPath('data.ignored_rows', 1);
        $this->getJson('/api/v1/imports/'.$second->json('data.id'))->assertOk()->assertJsonPath('data.ignored_rows', 1);
        $this->getJson('/api/v1/imports/'.$first->json('data.id'))->assertOk()->assertJsonPath('data.imported_rows', 1)->assertJsonPath('data.ignored_rows', 0);
        $this->assertArrayNotHasKey('source_content', $second->json('data'));
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('import_batches', 3);
    }

    public function test_same_file_from_another_user_is_imported_independently(): void
    {
        $content = $this->csv([$this->row()]);
        $this->upload($content)->assertCreated()->assertJsonPath('data.ignored_rows', 0);
        $other = User::factory()->create();
        $batch = app(CsvExpenseImporter::class)->import(UploadedFile::fake()->createWithContent('despesas.csv', $content), $other->id);
        $this->assertSame(1, $batch->imported_rows);
        $this->assertSame(0, $batch->ignored_rows);
        $this->assertNull($batch->original_import_id);
        $this->assertDatabaseCount('expenses', 2);
    }

    public function test_web_reupload_explains_the_existing_import_and_legacy_row_details(): void
    {
        $this->withoutVite();
        $content = $this->csv([$this->row(), $this->row()]);
        $this->actingAs($this->user)->post('/importacoes', [
            'file' => UploadedFile::fake()->createWithContent('despesas.csv', $content),
        ])->assertRedirect()->assertSessionHas('success', 'Arquivo processado: 2 despesas cadastradas e 0 linhas com erro. Confira o resultado abaixo.');

        $batch = ImportBatch::sole();
        $this->assertDatabaseCount('expenses', 2);
        $batch->update(['successful_rows' => null]);

        $this->post('/importacoes', [
            'file' => UploadedFile::fake()->createWithContent('despesas.csv', $content),
        ])->assertRedirect()
            ->assertSessionHas('success', 'Este arquivo já foi enviado por você. Nenhuma despesa foi cadastrada neste envio: 2 linhas ignoradas e 0 linhas com erro preservadas da importação #'.$batch->id.'. Confira o resultado abaixo.');

        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseCount('import_batches', 2);
        $repeat = ImportBatch::where('original_import_id', $batch->id)->sole();
        $this->get(route('imports.show', $repeat))->assertOk()
            ->assertSee('Ignoradas')->assertSee('Arquivo repetido')->assertSee('Nenhuma despesa foi registrada neste envio');
        $this->get('/importacoes')->assertOk()->assertSee('Ignoradas');
        $this->get(route('imports.show', $batch))->assertOk()
            ->assertSee('Esta importação antiga registrou 2 despesas')
            ->assertSee('Isso não é uma rejeição')
            ->assertSee('sem cadastrar as despesas novamente');
    }

    public function test_unexpected_failure_rolls_back_current_row_and_resume_skips_committed_rows(): void
    {
        $content = $this->csv([$this->row(), $this->row([1 => 'Second']), $this->row([5 => 'operacoes:80'])]);
        $calls = 0;
        Expense::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('Injected interruption');
            }
        });
        try {
            app(CsvExpenseImporter::class)->import(UploadedFile::fake()->createWithContent('resume.csv', $content), $this->user->id);
            $this->fail('Expected interruption');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected interruption', $exception->getMessage());
        } finally {
            Expense::flushEventListeners();
        }
        $batch = ImportBatch::sole();
        $this->assertSame('interrupted', $batch->status);
        $this->assertSame(1, $batch->total_rows);
        $this->assertDatabaseCount('expenses', 1);
        $this->postJson('/api/v1/imports/'.$batch->id.'/resume')->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.total_rows', 3)
            ->assertJsonPath('data.imported_rows', 2)->assertJsonPath('data.failed_rows', 1);
        $this->assertDatabaseCount('expenses', 2);
        $this->postJson('/api/v1/imports/'.$batch->id.'/resume')->assertOk();
        $this->assertDatabaseCount('expenses', 2);
    }

    public function test_upload_size_extension_and_required_file_are_validated(): void
    {
        $this->postJson('/api/v1/imports', [])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/imports', ['file' => UploadedFile::fake()->createWithContent('payload.exe', $this->csv([$this->row()]))])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/imports', ['file' => UploadedFile::fake()->create('huge.csv', 2049, 'text/csv')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('expenses', 0);
    }
}
