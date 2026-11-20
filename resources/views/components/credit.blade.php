{{-- Footer credit. The page carries only an address of this system; it sends the browser on to WhatsApp (0319-0855540) when clicked. --}}
<a href="{{ route('developer.whatsapp') }}" target="_blank" rel="noopener"
   {{ $attributes->merge(['class' => 'text-slate-500 underline-offset-2 hover:text-emerald-700 hover:underline']) }}
   title="Chat on WhatsApp: 0319-0855540">Design and developed by Engr. Syed Intissar Mehdi</a>
