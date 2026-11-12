<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class WidgetPresentacionTest extends TestCase
{
    public function test_public_widget_preserves_the_original_robot_and_keyboard_contract(): void
    {
        $html = view('components.public.chat-widget')->render();
        $this->assertStringContainsString('id="horus-chat-panel"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('hc-robot-wave', $html);
        $this->assertStringContainsString('@keydown.escape.window', $html);
        $this->assertStringContainsString('No compartas datos personales', $html);
        $this->assertStringContainsString('data-endpoint=', $html);
    }

    public function test_public_cards_use_text_bindings_and_no_html_interpretation(): void
    {
        $html = view('components.chat.conversation', ['endpoint' => '/chat/mensaje', 'public' => true])->render();
        $this->assertStringContainsString('x-text="card.titulo"', $html);
        $this->assertStringContainsString('x-text="card.descripcion"', $html);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $html);
        $this->assertStringContainsString('x-on:error="card.imagen = null"', $html);
        $this->assertStringNotContainsString('x-html', $html);
        $this->assertStringContainsString('!$event.isComposing', $html);
    }

    public function test_administrative_conversation_keeps_its_existing_presentation(): void
    {
        $html = view('components.chat.conversation', ['endpoint' => '/admin/asistente/mensaje', 'public' => false])->render();
        $this->assertStringNotContainsString('hc-card-body', $html);
        $this->assertStringNotContainsString('hc-privacy-hint', $html);
        $this->assertStringContainsString('data-endpoint="/admin/asistente/mensaje"', $html);
    }
}
