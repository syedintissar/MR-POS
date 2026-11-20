<?php

namespace Tests\Feature\Admin;

use App\Actions\Sales\CompleteSale;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\PrintFont;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Fonts for printed receipts: one for thermal receipts, one for A4 prints, and darker (bold) printing. */
class PrintFontTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private function printing(array $over = []): array
    {
        return array_merge([
            'printing_default_format' => 'thermal', 'printing_printer_name' => '', 'printing_printer_model' => '', 'printing_paper_width_mm' => '80',
            'printing_margin_top_mm' => '2', 'printing_margin_right_mm' => '2', 'printing_margin_bottom_mm' => '4', 'printing_margin_left_mm' => '2',
            'printing_font_size_pt' => '9', 'printing_thermal_font' => 'consolas', 'printing_a4_font' => 'segoe', 'printing_bold_text' => 0, 'printing_copies' => 1,
            'printing_auto_print_sale' => 1, 'printing_auto_print_job_slip' => 1, 'printing_auto_print_payment' => 0, 'printing_open_cash_drawer' => 0,
        ], $over);
    }

    private function sale(): Sale
    {
        $p = $this->stocked('5', '100', ['name' => 'Canon ink', 'sale_price' => '300']);

        return app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $p->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'amount' => '300']],
        ], User::factory()->withRole('Manager')->create());
    }

    public function test_the_defaults_keep_todays_fonts_and_every_choice_is_a_windows_font(): void
    {
        $s = app(SettingsService::class);
        $this->assertSame('consolas', $s->get('printing.thermal_font'));
        $this->assertSame('segoe', $s->get('printing.a4_font'));
        $this->assertFalse($s->bool('printing.bold_text'));
        $this->assertGreaterThanOrEqual(12, count(PrintFont::FONTS));
        foreach (PrintFont::FONTS as [$label, $stack]) {
            $this->assertMatchesRegularExpression('/(monospace|sans-serif|serif|cursive)$/', $stack, "{$label} falls back to a generic font");
            $this->assertStringNotContainsString('url(', $stack, 'no font is downloaded');
        }
        $this->assertSame(PrintFont::FONTS['consolas'][1], PrintFont::stack('no-such-font'));
    }

    public function test_the_chosen_fonts_and_bold_print_on_receipts_invoices_and_quotations(): void
    {
        $sale = $this->sale();
        $admin = User::factory()->withRole('Admin')->create();
        $this->actingAs($admin);

        $html = $this->get("/sales/{$sale->id}/reprint/thermal")->assertOk()->getContent();
        $this->assertStringContainsString(PrintFont::FONTS['consolas'][1], $html);
        $this->assertDoesNotMatchRegularExpression('/body \{[^}]*font-weight: 700/', $html, 'normal weight by default');

        $this->get('/admin/settings/printing')->assertOk()->assertSee('Font for thermal receipts')->assertSee('Font for A4 invoices and quotations')
            ->assertSee('Georgia (elegant serif)')->assertSee('font-preview', false);
        $this->put('/admin/settings/printing', $this->printing(['printing_thermal_font' => 'courier', 'printing_a4_font' => 'georgia', 'printing_bold_text' => 1]))
            ->assertRedirect('/admin/settings/printing')->assertSessionHasNoErrors();

        $thermal = $this->get("/sales/{$sale->id}/reprint/thermal")->assertOk()->getContent();
        $this->assertStringContainsString(PrintFont::FONTS['courier'][1], $thermal);
        $this->assertMatchesRegularExpression('/body \{[^}]*font-weight: 700/', $thermal, 'bold when asked for');
        $a4 = $this->get("/sales/{$sale->id}/reprint/a4")->assertOk()->getContent();
        $this->assertStringContainsString(PrintFont::FONTS['georgia'][1], $a4);
        $this->get("/sales/{$sale->id}/pdf")->assertOk();   // the PDF keeps its own built-in font

        $this->post('/quotations', ['customer_name' => 'Office', 'quote_date' => now()->toDateString(), 'lines' => [['description' => 'Toner', 'quantity' => '1', 'unit_price' => '100']]]);
        $qid = Quotation::query()->value('id');
        $this->assertStringContainsString(PrintFont::FONTS['georgia'][1], $this->get("/quotations/{$qid}/print")->assertOk()->getContent());

        // only fonts from the list are accepted
        $this->put('/admin/settings/printing', $this->printing(['printing_thermal_font' => 'Wingdings', 'printing_a4_font' => 'url(evil)']))
            ->assertSessionHasErrors(['printing_thermal_font', 'printing_a4_font']);
        $this->assertSame('courier', app(SettingsService::class)->get('printing.thermal_font'));
    }
}
