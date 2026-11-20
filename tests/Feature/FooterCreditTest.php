<?php

namespace Tests\Feature;

use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The developer credit is in the footer of the login page and of every signed-in page, and opens WhatsApp when clicked. */
class FooterCreditTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    public function test_the_credit_is_on_the_login_page_and_inside_and_opens_the_developers_whatsapp(): void
    {
        $text = 'Design and developed by Engr. Syed Intissar Mehdi';
        $link = 'href="'.route('developer.whatsapp').'"';

        $this->get('/login')->assertOk()->assertSee($text)->assertSee($link, false);
        foreach (['Admin', 'Cashier', 'Technician'] as $role) {
            $this->as($role)->get('/')->assertOk()->assertSee($text)->assertSee($link, false);
        }

        // signed in or not, the address sends the browser on to the WhatsApp chat
        $this->get('/developer')->assertRedirect('https://wa.me/923190855540');
        auth()->logout();
        $this->get('/developer')->assertRedirect('https://wa.me/923190855540');
    }
}
