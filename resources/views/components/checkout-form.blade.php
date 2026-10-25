@if ($bootstrapScript !== '')
<div id="{{ $mountId }}" {{ $attributes->merge(['class' => 'lunixi-checkout-form']) }}></div>
{!! $bootstrapScript !!}
@else
<p {{ $attributes->merge(['class' => 'lunixi-checkout-form-unavailable', 'role' => 'alert']) }}>{{ __('lunixi::checkout.unavailable') }}</p>
@endif
