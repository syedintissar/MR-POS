<?php

namespace Tests\Feature\Admin;

use App\Models\DocumentSequence;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshesSeededDatabase;

    private function admin(): User
    {
        return User::factory()->withRole('Admin')->create();
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_defaults_match_the_owner_decisions(): void
    {
        $s = $this->settings();

        $this->assertSame('Mr Printer & Tuner', $s->get('shop.name'));
        $this->assertSame('PKR', $s->get('currency.code'));
        $this->assertSame('Rs.', $s->get('currency.symbol'));
        $this->assertFalse($s->bool('tax.enabled'), 'The shop is not registered for sales tax.');
        $this->assertFalse($s->bool('inventory.allow_negative_stock'));
        $this->assertSame('thermal', $s->get('printing.default_format'));
        $this->assertSame('80', $s->get('printing.paper_width_mm'));
        $this->assertSame('21:00', $s->get('backup.daily_time'));
        $this->assertSame(7, $s->int('backup.retention_daily'));
        $this->assertSame(4, $s->int('backup.retention_weekly'));
        $this->assertSame(6, $s->int('backup.retention_monthly'));
    }

    public function test_sales_tax_can_be_switched_on_later_from_settings_without_code_changes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings/tax', [
            'tax_enabled' => 1, 'tax_label' => 'GST', 'tax_default_rate' => '18', 'tax_inclusive' => 0, 'tax_purchase_tax_in_cost' => 1,
        ])->assertRedirect('/admin/settings/tax');

        $s = $this->settings();
        $this->assertTrue($s->bool('tax.enabled'));
        $this->assertSame('18', $s->get('tax.default_rate'));
        $this->assertFalse($s->bool('tax.inclusive'));
    }

    public function test_unticked_checkboxes_save_as_off(): void
    {
        $admin = $this->admin();
        $this->settings()->set('tax.enabled', true);

        $this->actingAs($admin)->put('/admin/settings/tax', [
            'tax_enabled' => 0, 'tax_label' => 'GST', 'tax_default_rate' => '0', 'tax_inclusive' => 0, 'tax_purchase_tax_in_cost' => 1,
        ]);

        $this->assertFalse($this->settings()->bool('tax.enabled'));
    }

    public function test_a_settings_change_is_audited_with_old_and_new_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings/sales', [
            'sales_max_discount_percent' => '10', 'sales_void_window_hours' => 24, 'sales_store_credit_enabled' => 1, 'sales_round_to' => '5',
        ])->assertRedirect();

        $row = DB::table('audit_logs')->where('event', 'settings_changed')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame('5.00', json_decode($row->old_values, true)['sales.max_discount_percent']);
        $this->assertSame('10', json_decode($row->new_values, true)['sales.max_discount_percent']);
    }

    public function test_saving_without_any_change_writes_no_audit_entry(): void
    {
        $admin = $this->admin();
        $before = DB::table('audit_logs')->where('event', 'settings_changed')->count();

        $this->actingAs($admin)->put('/admin/settings/sales', [
            'sales_max_discount_percent' => '5.00', 'sales_void_window_hours' => 24, 'sales_store_credit_enabled' => 1, 'sales_round_to' => '0',
        ]);

        $this->assertSame($before, DB::table('audit_logs')->where('event', 'settings_changed')->count());
    }

    public function test_invalid_values_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings/tax', ['tax_enabled' => 1, 'tax_label' => 'GST', 'tax_default_rate' => '150', 'tax_inclusive' => 0, 'tax_purchase_tax_in_cost' => 1])
            ->assertSessionHasErrors('tax_default_rate');
        $this->actingAs($admin)->put('/admin/settings/shop', ['shop_name' => '', 'shop_email' => 'not-an-email'])
            ->assertSessionHasErrors(['shop_name', 'shop_email']);
    }

    public function test_printer_settings_are_configurable_and_validated(): void
    {
        $admin = $this->admin();
        $valid = [
            'printing_default_format' => 'thermal', 'printing_printer_name' => 'Counter 1', 'printing_printer_model' => 'Generic 58mm',
            'printing_paper_width_mm' => '58', 'printing_margin_top_mm' => '1', 'printing_margin_right_mm' => '1', 'printing_margin_bottom_mm' => '3',
            'printing_margin_left_mm' => '1', 'printing_font_size_pt' => '8', 'printing_copies' => 2,
            'printing_auto_print_sale' => 0, 'printing_auto_print_job_slip' => 1, 'printing_auto_print_payment' => 0, 'printing_open_cash_drawer' => 1,
        ];

        $this->actingAs($admin)->put('/admin/settings/printing', $valid)->assertRedirect('/admin/settings/printing');

        $s = $this->settings();
        $this->assertSame('58', $s->get('printing.paper_width_mm'));
        $this->assertSame(2, $s->int('printing.copies'));
        $this->assertFalse($s->bool('printing.auto_print_sale'));
        $this->assertTrue($s->bool('printing.open_cash_drawer'));
        $this->assertSame('Generic 58mm', $s->get('printing.printer_model'));

        $this->actingAs($admin)->put('/admin/settings/printing', array_merge($valid, ['printing_paper_width_mm' => '70', 'printing_copies' => 50]))
            ->assertSessionHasErrors(['printing_paper_width_mm', 'printing_copies']);
    }

    public function test_cashier_and_manager_cannot_change_settings_but_a_manager_can_view_them(): void
    {
        $this->actingAs(User::factory()->withRole('Cashier')->create())->get('/admin/settings')->assertForbidden();

        $manager = User::factory()->withRole('Manager')->create();
        $this->actingAs($manager)->get('/admin/settings/tax')->assertOk()->assertSee('You can view these settings but not change them');
        $this->actingAs($manager)->put('/admin/settings/tax', ['tax_enabled' => 1])->assertForbidden();
        $this->assertFalse($this->settings()->bool('tax.enabled'));
    }

    public function test_the_ip_allow_list_cannot_lock_the_current_user_out(): void
    {
        $admin = $this->admin();
        $lan = ['REMOTE_ADDR' => '192.168.1.50'];
        $form = ['security_password_min_length' => 8, 'security_session_idle_minutes' => 480];

        // A list that does not include my own address is refused.
        $this->actingAs($admin)->withServerVariables($lan)->put('/admin/settings/security', $form + ['security_ip_allowlist' => '10.0.0.0/8'])
            ->assertSessionHasErrors('security_ip_allowlist');
        $this->assertSame('', $this->settings()->string('security.ip_allowlist'));

        // A list that includes it is accepted.
        $this->actingAs($admin)->withServerVariables($lan)->put('/admin/settings/security', $form + ['security_ip_allowlist' => "192.168.1.0/24\n10.0.0.5"])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('192.168.1.0/24', $this->settings()->string('security.ip_allowlist'));
    }

    public function test_the_ip_allow_list_blocks_other_machines_but_never_the_server_itself(): void
    {
        $this->settings()->set('security.ip_allowlist', '192.168.1.0/24');

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.77'])->get('/login')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->get('/login')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/login')->assertOk();
    }

    public function test_logo_upload_is_reencoded_validated_and_served(): void
    {
        $admin = $this->admin();
        $base = ['shop_name' => 'Mr Printer & Tuner'];

        $this->actingAs($admin)->put('/admin/settings/shop', $base + ['logo' => UploadedFile::fake()->image('logo.png', 800, 400)])
            ->assertSessionHasNoErrors();

        $path = $this->settings()->string('shop.logo_path');
        $this->assertStringStartsWith('branding/logo-', $path);
        $file = config('mrpos.files_path').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $this->assertFileExists($file);
        $this->assertLessThanOrEqual(600, getimagesize($file)[0], 'Logo is scaled down.');
        $this->get('/branding/logo')->assertOk();

        // A script renamed to .png is refused.
        $evil = UploadedFile::fake()->createWithContent('logo.png', '<?php system($_GET["c"]); ?>');
        $this->actingAs($admin)->put('/admin/settings/shop', $base + ['logo' => $evil])->assertSessionHasErrors('logo');
    }

    public function test_document_numbering_can_be_customised_and_is_audited(): void
    {
        $admin = $this->admin();
        $seq = DocumentSequence::where('doc_type', 'sale')->firstOrFail();

        $this->actingAs($admin)->put('/admin/settings-numbering', ['seq' => [$seq->id => ['prefix' => 'MPT-', 'padding' => 5, 'reset_yearly' => 0]]])
            ->assertRedirect('/admin/settings/numbering');

        $seq->refresh();
        $this->assertSame('MPT-', $seq->prefix);
        $this->assertSame(5, $seq->padding);
        $this->assertDatabaseHas('audit_logs', ['event' => 'numbering_changed']);

        $this->actingAs($admin)->put('/admin/settings-numbering', ['seq' => [$seq->id => ['prefix' => 'bad prefix!', 'padding' => 5]]])
            ->assertSessionHasErrors('seq.'.$seq->id.'.prefix');
    }

    public function test_the_settings_pages_render_for_every_tab(): void
    {
        $admin = $this->admin();

        foreach (['shop', 'currency', 'tax', 'sales', 'inventory', 'printing', 'receipt', 'security', 'numbering'] as $tab) {
            $this->actingAs($admin)->get("/admin/settings/{$tab}")->assertOk();
        }
        $this->actingAs($admin)->get('/admin/settings/nonsense')->assertNotFound();
    }
}
