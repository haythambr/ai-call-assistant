@props(['seed' => 'call', 'bars' => 48, 'live' => false])
@php
    // A voice fingerprint: runs of cobalt (the AI) and saffron (the caller),
    // always the same for the same seed, like the waves on the call desk.
    mt_srand(crc32((string) $seed));
    $voice = 'agent';
    $left = mt_rand(3, 9);
    $out = [];
    for ($i = 0; $i < $bars; $i++) {
        if ($left-- <= 0) {
            $voice = $voice === 'agent' ? 'caller' : 'agent';
            $left = mt_rand(3, 10);
        }
        $edge = sqrt(sin(M_PI * ($i + 0.5) / $bars));
        $out[] = ['h' => round(0.2 + 0.8 * $edge * mt_rand(40, 100) / 100, 2), 'v' => $voice];
    }
    mt_srand();
@endphp
<span {{ $attributes->class(['vwave', 'is-live' => $live]) }} aria-hidden="true">@foreach ($out as $i => $bar)<i class="{{ $bar['v'] === 'caller' ? 'v-caller' : '' }}" style="--h: {{ $bar['h'] }}; --i: {{ $i }}"></i>@endforeach</span>
