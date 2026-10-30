@props(['wording', 'toc' => true])

{{--
    The agreement's wording, shown as text (docs/slice-33-agreement-plan.md,
    step 33a): its title, a table of contents, and each numbered section's
    paragraphs and lists. Everything is escaped; nothing in it runs.
--}}
@php($read = $wording->sections())

<article {{ $attributes->class('flex flex-col gap-5') }} data-test="agreement-wording">
    <flux:heading size="lg" level="2" class="text-balance">{{ $wording->title }}</flux:heading>

    @if ($toc && $read['sections'] !== [])
        <nav aria-label="{{ __('Sections') }}" class="rounded-xl border border-zinc-200 bg-card p-4" data-test="agreement-toc">
            <flux:heading level="3" class="mb-2">{{ __('Sections') }}</flux:heading>
            <ol class="columns-1 gap-6 text-sm sm:columns-2 lg:columns-3">
                @foreach ($read['sections'] as $section)
                    <li class="break-inside-avoid py-0.5">
                        <a href="#agreement-section-{{ $section['number'] }}" class="text-zinc-700 underline-offset-2 hover:underline">{{ $section['number'] }}. {{ $section['title'] }}</a>
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    @foreach ($read['intro'] as $block)
        @include('components.agreement.wording-block', ['block' => $block])
    @endforeach

    @foreach ($read['sections'] as $section)
        <section id="agreement-section-{{ $section['number'] }}" class="flex scroll-mt-24 flex-col gap-2" data-test="agreement-section-{{ $section['number'] }}">
            <flux:heading level="3">{{ $section['number'] }}. {{ $section['title'] }}</flux:heading>
            @foreach ($section['blocks'] as $block)
                @include('components.agreement.wording-block', ['block' => $block])
            @endforeach
        </section>
    @endforeach
</article>
