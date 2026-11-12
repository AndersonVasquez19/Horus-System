@props(['id' => 'password', 'name' => 'password', 'placeholder' => 'Tu contraseña', 'autocomplete' => 'current-password', 'minlength' => null])

<div class="auth-input-wrap auth-input-wrap--password" x-data="{ visible: false }">
    <i class="fa-solid fa-lock" aria-hidden="true"></i>
    <input id="{{ $id }}" name="{{ $name }}" type="password" :type="visible ? 'text' : 'password'"
           autocomplete="{{ $autocomplete }}" placeholder="{{ $placeholder }}" required
           aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"
           @if ($minlength) minlength="{{ $minlength }}" @endif>
    <button type="button" class="auth-password-toggle" @click="visible = !visible" x-cloak
            :aria-label="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'"
            :aria-pressed="visible" aria-controls="{{ $id }}">
        <i class="fa-regular" :class="visible ? 'fa-eye-slash' : 'fa-eye'" aria-hidden="true"></i>
    </button>
</div>
