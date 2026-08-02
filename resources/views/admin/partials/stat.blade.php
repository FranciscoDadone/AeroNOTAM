{{-- $valueClass takes whole utility classes, never interpolated fragments:
     Tailwind only generates what it can find spelled out in a file. --}}
<div class="rounded-xl border border-line bg-ink p-4 sm:p-5">
  <div class="eyebrow mb-2">{{ $label }}</div>
  <div class="text-[1.6rem] font-semibold tracking-[-0.03em] tabular-nums sm:text-[1.9rem] {{ $valueClass ?? 'text-body' }}">{{ $value }}</div>
  @isset($hint)
    <div class="mt-1 text-[0.85rem] text-dim">{{ $hint }}</div>
  @endisset
</div>
