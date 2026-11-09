{{-- Prompt voor Claude in Chrome; inline stijlen, want pakketviews vallen buiten de Tailwind-build. --}}
<details x-data="{ gekopieerd: false }" style="border: 1px solid rgba(127,127,127,.3); border-radius: 8px; padding: 8px 12px;">
    <summary style="cursor: pointer; font-size: 14px;">
        Toon prompt (kopieer en geef hem aan Claude in Chrome terwijl je ingelogd bent bij het platform)
    </summary>
    <textarea x-ref="prompt" readonly rows="16"
              style="width: 100%; margin-top: 8px; font-family: ui-monospace, monospace; font-size: 12px; padding: 8px; border: 1px solid rgba(127,127,127,.3); border-radius: 6px; background: transparent; color: inherit;">{{ $prompt }}</textarea>
    <x-filament::button size="sm" color="gray" icon="heroicon-o-clipboard" style="margin-top: 6px;"
        x-on:click.prevent="
            const tekst = $refs.prompt.value;
            (navigator.clipboard ? navigator.clipboard.writeText(tekst) : Promise.reject())
                .catch(() => { $refs.prompt.select(); document.execCommand('copy'); })
                .finally(() => { gekopieerd = true; setTimeout(() => gekopieerd = false, 2000); });
        ">
        <span x-text="gekopieerd ? 'Gekopieerd' : 'Kopieer prompt'">Kopieer prompt</span>
    </x-filament::button>
</details>
