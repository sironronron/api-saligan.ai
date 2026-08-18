<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
    .content { width: 100% !important; }
    .inner-body { width: 100% !important; }
    .footer { width: 100% !important; }
    .content-cell { padding: 24px 20px !important; }
    .header { padding: 28px 20px !important; }
}
@media only screen and (max-width: 500px) {
    .button { width: 100% !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body style="margin: 0; padding: 0; background-color: #F7EAE0;">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #F7EAE0; padding: 32px 0;">
<tr>
<td align="center">

<table class="content" width="600" cellpadding="0" cellspacing="0" role="presentation" style="width: 600px; max-width: 600px; border-collapse: collapse;">
{!! $header ?? '' !!}

<!-- Peach accent bar -->
<tr>
<td style="background-color: #F9D2BA; height: 6px; font-size: 0; line-height: 0;">&nbsp;</td>
</tr>

<!-- Body card -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="background-color: #FCEADE; border-left: 1px solid #E5BA9A; border-right: 1px solid #E5BA9A;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="width: 570px; max-width: 570px; background-color: #FCEADE;">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
