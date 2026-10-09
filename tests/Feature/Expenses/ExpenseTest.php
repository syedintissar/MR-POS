<?php

namespace Tests\Feature\Expenses;

use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    private function data(array $over = []): array
    {
        return $over + [
            'expense_category_id' => ExpenseCategory::query()->where('name', 'Electricity')->value('id'), 'expense_date' => now()->toDateString(), 'amount' => '4500',
            'payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'payee' => 'K-Electric', 'description' => 'September bill',
        ];
    }

    private function save(array $over = [], ?User $by = null, ?UploadedFile $file = null): Expense
    {
        return app(SaveExpense::class)->execute($this->data($over), $by ?? User::factory()->withRole('Manager')->create(), null, $file);
    }

    // ---------------------------------------------------------------- the action

    public function test_the_nine_default_categories_exist(): void
    {
        $this->assertSame(['Electricity', 'Internet', 'Maintenance', 'Miscellaneous', 'Office expense', 'Rent', 'Salary', 'Tea / Refreshments', 'Transport'], ExpenseCategory::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_an_expense_is_recorded_numbered_and_audited(): void
    {
        $e = $this->save();

        $this->assertSame('EXP-000001', $e->expense_no);
        $this->assertSame('4500.00', (string) $e->amount);
        $this->assertSame('posted', $e->status);
        $this->assertSame('K-Electric', $e->payee);
        $this->assertSame(1, AuditLog::query()->where('event', 'expense_created')->count());
        $this->assertSame('EXP-000002', $this->save()->expense_no);
    }

    public function test_bad_expenses_are_refused(): void
    {
        foreach ([
            ['amount' => '0'], ['amount' => '-5'], ['description' => '  '], ['expense_category_id' => 9999], ['payment_method_id' => 9999],
            ['expense_date' => now()->addDay()->toDateString()], ['expense_date' => 'not a date'],
            ['payment_method_id' => PaymentMethod::query()->where('kind', 'customer_credit')->value('id')],
        ] as $bad) {
            try {
                $this->save($bad);
                $this->fail('Accepted: '.json_encode($bad));
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Expense::query()->count());

        ExpenseCategory::query()->where('name', 'Rent')->update(['is_active' => false]);
        $this->expectException(DomainException::class);
        $this->save(['expense_category_id' => ExpenseCategory::query()->where('name', 'Rent')->value('id')]);
    }

    public function test_only_people_who_may_record_expenses_can(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->save([], User::factory()->withRole('Technician')->create());
    }

    public function test_a_correction_works_on_the_day_and_is_audited_with_old_and_new_values(): void
    {
        $manager = User::factory()->withRole('Manager')->create();
        $e = $this->save([], $manager);

        $e = app(SaveExpense::class)->execute($this->data(['amount' => '4800', 'description' => 'September bill plus late fee']), $manager, $e);

        $this->assertSame('4800.00', (string) $e->amount);
        $log = AuditLog::query()->where('event', 'expense_updated')->firstOrFail();
        $this->assertSame('4500.00', $log->old_values['amount']);
        $this->assertSame('4800.00', $log->new_values['amount']);
    }

    public function test_after_that_day_an_expense_can_only_be_cancelled(): void
    {
        $manager = User::factory()->withRole('Manager')->create();
        $e = $this->save([], $manager);
        DB::table('expenses')->where('id', $e->id)->update(['created_at' => Carbon::now()->subDay()]);

        try {
            app(SaveExpense::class)->execute($this->data(['amount' => '1']), $manager, $e->fresh());
            $this->fail('An old expense was edited.');
        } catch (DomainException $e2) {
            $this->assertStringContainsString('day it was entered', $e2->getMessage());
        }
        $this->assertSame('4500.00', (string) $e->fresh()->amount);

        $void = app(VoidExpense::class)->execute($e->fresh(), 'Entered twice', $manager);
        $this->assertTrue($void->isVoided());
        $this->assertSame('Entered twice', $void->void_reason);
        $this->assertSame(1, AuditLog::query()->where('event', 'expense_voided')->count());

        $this->expectException(DomainException::class);   // never twice
        app(VoidExpense::class)->execute($void, 'again', $manager);
    }

    public function test_cancelling_needs_a_reason_and_the_permission(): void
    {
        $e = $this->save();
        try {
            app(VoidExpense::class)->execute($e, '  ', User::factory()->withRole('Manager')->create());
            $this->fail('Cancelled without a reason.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(AuthorizationException::class);
        app(VoidExpense::class)->execute($e, 'x', User::factory()->withRole('Cashier')->create());
    }

    // ---------------------------------------------------------------- attachments

    public function test_an_attachment_is_kept_in_a_private_folder_and_replaced_on_correction(): void
    {
        Storage::fake('local');
        $manager = User::factory()->withRole('Manager')->create();
        $e = $this->save([], $manager, UploadedFile::fake()->create('bill.pdf', 200, 'application/pdf'));

        $this->assertNotNull($e->attachment_path);
        $this->assertStringStartsWith('expenses/', $e->attachment_path);
        $this->assertSame('bill.pdf', $e->attachment_name);
        Storage::disk('local')->assertExists($e->attachment_path);
        $first = $e->attachment_path;

        $e = app(SaveExpense::class)->execute($this->data(), $manager, $e, UploadedFile::fake()->image('receipt.png'));
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($e->attachment_path);
    }

    public function test_a_failed_save_leaves_no_uploaded_file_behind(): void
    {
        Storage::fake('local');

        try {
            $this->save(['amount' => '0'], null, UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf'));
        } catch (DomainException) {
            // expected
        }
        $this->assertSame([], Storage::disk('local')->allFiles());

        // a file of the wrong kind or too big is refused before anything is stored
        foreach ([UploadedFile::fake()->create('virus.exe', 10), UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')] as $file) {
            try {
                $this->save([], null, $file);
                $this->fail('A bad attachment was accepted.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ---------------------------------------------------------------- the pages

    public function test_the_pages_work_and_the_attachment_is_served_only_to_people_who_may_see_expenses(): void
    {
        Storage::fake('local');
        $e = $this->save([], User::factory()->withRole('Manager')->create(), UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'));

        $this->as('Manager');
        $this->get('/expenses')->assertOk()->assertSee($e->expense_no)->assertSee('4,500.00');
        $this->get('/expenses?q=Electric&category='.$e->expense_category_id.'&status=all&from=2020-01-01&to=2099-01-01&method='.$e->payment_method_id)->assertOk()->assertSee($e->expense_no);
        $this->get('/expenses/create')->assertOk();
        $this->get("/expenses/{$e->id}")->assertOk()->assertSee('K-Electric')->assertSee('Open the bill');
        $this->get('/expenses/categories')->assertOk()->assertSee('Rent');
        $this->get("/expenses/{$e->id}/attachment")->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->as('Technician')->get("/expenses/{$e->id}/attachment")->assertForbidden();
        $this->get('/expenses')->assertForbidden();
        $this->assertSame(1, AuditLog::query()->where('event', 'expense_attachment_viewed')->count());
    }

    public function test_recording_through_the_form(): void
    {
        Storage::fake('local');
        $this->as('Cashier');

        $this->post('/expenses', $this->data(['amount' => '250', 'description' => 'Tea and biscuits', 'attachment' => UploadedFile::fake()->image('slip.jpg')]))->assertRedirect();
        $e = Expense::query()->firstOrFail();
        $this->assertSame('250.00', (string) $e->amount);
        $this->assertNotNull($e->attachment_path);

        $this->post('/expenses', $this->data(['amount' => '0']))->assertSessionHasErrors('amount');
        $this->post('/expenses', $this->data(['amount' => '10.555']))->assertSessionHasErrors('amount');
        $this->post('/expenses', $this->data(['expense_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('expense_date');
        $this->post('/expenses', $this->data(['attachment' => UploadedFile::fake()->create('x.exe', 10)]))->assertSessionHasErrors('attachment');
        $this->post('/expenses', $this->data(['attachment' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]))->assertSessionHasErrors('attachment');
        $this->assertSame(1, Expense::query()->count());

        // a cashier may record but not correct or cancel
        $this->get("/expenses/{$e->id}/edit")->assertForbidden();
        $this->put("/expenses/{$e->id}", $this->data())->assertForbidden();
        $this->post("/expenses/{$e->id}/void", ['reason' => 'x'])->assertForbidden();
    }

    public function test_a_manager_corrects_today_and_cancels_with_the_password(): void
    {
        $e = $this->save([], User::factory()->withRole('Manager')->create());
        $this->as('Manager');

        $this->put("/expenses/{$e->id}", $this->data(['amount' => '5000']))->assertRedirect(route('expenses.show', $e));
        $this->assertSame('5000.00', (string) $e->fresh()->amount);

        $this->post("/expenses/{$e->id}/void", ['reason' => 'Entered twice']);
        $this->assertFalse($e->fresh()->isVoided(), 'the password was not asked for');
        $this->withSession($this->confirmed())->post("/expenses/{$e->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->withSession($this->confirmed())->post("/expenses/{$e->id}/void", ['reason' => 'Entered twice'])->assertRedirect(route('expenses.show', $e));
        $this->assertTrue($e->fresh()->isVoided());

        $this->get("/expenses/{$e->id}/edit")->assertForbidden();   // cancelled: no longer editable
        $this->get("/expenses/{$e->id}")->assertOk()->assertSee('Cancelled')->assertSee('Entered twice');
    }

    public function test_categories_are_managed_by_people_who_may_edit_expenses(): void
    {
        $this->as('Manager');
        $this->post('/expenses/categories', ['name' => 'Insurance'])->assertSessionHas('success');
        $cat = ExpenseCategory::query()->where('name', 'Insurance')->firstOrFail();
        $this->post('/expenses/categories', ['name' => 'Insurance'])->assertSessionHasErrors('name');
        $this->put("/expenses/categories/{$cat->id}", ['name' => 'Insurance & tax'])->assertSessionHas('success');
        $this->post("/expenses/categories/{$cat->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($cat->fresh()->is_active);

        $this->as('Cashier');
        $this->get('/expenses/categories')->assertOk();
        $this->post('/expenses/categories', ['name' => 'Nope'])->assertForbidden();
        $this->post("/expenses/categories/{$cat->id}/toggle")->assertForbidden();
    }
}
