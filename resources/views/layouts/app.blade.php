<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-stone-950 text-stone-100 antialiased">
        <div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(249,115,22,0.22),_transparent_25rem),linear-gradient(180deg,_#14110f_0%,_#1c1917_45%,_#0c0a09_100%)]">
            @yield('content')
        </div>
    </body>
</html>
