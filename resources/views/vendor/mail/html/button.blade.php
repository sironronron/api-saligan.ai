@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 32px 0;">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center" style="background-color: #1D4533; border-radius: 10px; box-shadow: 0 4px 12px rgba(29, 69, 51, 0.25);">
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="display: inline-block; padding: 16px 40px; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; font-weight: 700; color: #F7EAE0; text-decoration: none; letter-spacing: 0.3px;">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>