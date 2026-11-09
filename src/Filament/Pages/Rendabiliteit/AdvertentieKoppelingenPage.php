<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit;

use UnitEnum;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\KoppelingInstellingen;
use Dashed\DashedEcommerceRendabiliteit\Http\Controllers\GoogleKoppelingController;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen\KoppelingPrompt;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen\AdvertentieKoppelingen;

/** Inloggegevens van Meta, Google en Bol, de laatste stand en "Nu ophalen". */
class AdvertentieKoppelingenPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    private const WAARDEN = ['meta_account', 'google_client_id', 'google_customer_id', 'google_login_customer_id', 'bol_client_id'];

    private const GEHEIMEN = ['meta_token', 'google_client_secret', 'google_refresh_token', 'google_developer_token', 'bol_client_secret'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|UnitEnum|null $navigationGroup = 'Rendabiliteit';

    protected static ?string $navigationLabel = 'Advertentiekoppelingen';

    protected static ?string $title = 'Advertentiekoppelingen';

    protected static ?string $slug = 'rendabiliteit/advertentie-koppelingen';

    protected static ?int $navigationSort = 25;

    protected string $view = 'dashed-core::settings.pages.default-settings';

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->can('view_statistics');
    }

    public function mount(): void
    {
        $data = [];

        foreach ([KoppelingInstellingen::META, KoppelingInstellingen::GOOGLE, KoppelingInstellingen::BOL] as $koppeling) {
            $data["{$koppeling}_aan"] = KoppelingInstellingen::aan($koppeling);
        }

        foreach (self::WAARDEN as $veld) {
            $data[$veld] = KoppelingInstellingen::waarde($veld);
        }

        // Geheimen nooit terugtonen; leeg laten betekent ongewijzigd.
        foreach (self::GEHEIMEN as $veld) {
            $data[$veld] = '';
        }

        $this->form->fill($data);

        if ($terug = session()->pull('rendabiliteit.google_koppeling')) {
            $melding = Notification::make()->title('Google')->body($terug['melding']);
            $terug['gelukt'] ? $melding->success() : $melding->danger()->persistent();
            $melding->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ophalen')
                ->label('Nu ophalen (7 dagen)')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->haalOp()),
            Action::make('geschiedenis')
                ->label('Geschiedenis ophalen')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->modalDescription('Haalt alle dagen vanaf de startdatum tot en met gisteren op en vervangt geschatte of geïmporteerde bedragen; handmatige regels blijven staan. Meta gaat hoogstens 37 maanden terug, Bol 30 dagen, Google onbeperkt. Alleen koppelingen met "Elke nacht ophalen" aan. Kan een paar minuten duren.')
                ->schema([
                    DatePicker::make('van')
                        ->label('Vanaf')
                        ->required()
                        ->maxDate(now()->subDay())
                        ->default(fn () => (AdvertentieDashboard::eersteOrderdag() ?? now()->subYear())->toDateString()),
                    Select::make('platform')
                        ->label('Kanaal')
                        ->placeholder('Alle kanalen')
                        ->options([KoppelingInstellingen::META => 'Meta', KoppelingInstellingen::GOOGLE => 'Google Ads', KoppelingInstellingen::BOL => 'Bol']),
                ])
                ->action(fn (array $data) => $this->haalOp(CarbonImmutable::parse($data['van'])->startOfDay(), $data['platform'] ?? null)),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->schema([
            Section::make('Meta')
                ->headerActions([$this->controleerActie(KoppelingInstellingen::META)])
                ->description('Business Manager → Systeemgebruikers: token met ads_read op het advertentieaccount.')
                ->schema([
                    Toggle::make('meta_aan')->label('Elke nacht ophalen'),
                    TextInput::make('meta_account')->label('Advertentieaccount-ID')->helperText('Bijvoorbeeld act_1234567890.'),
                    $this->geheimVeld('meta_token', 'Toegangstoken'),
                    $this->standVeld(KoppelingInstellingen::META),
                    $this->promptVeld(KoppelingInstellingen::META),
                ]),
            Section::make('Google Ads')
                ->headerActions([
                    Action::make('koppel_google')
                        ->label('Koppel met Google')
                        ->icon('heroicon-o-link')
                        ->size('sm')
                        ->tooltip('Haalt de refresh token op door in te loggen bij Google. Vul eerst client-ID en secret in.')
                        ->action(function () {
                            $this->bewaar();
                            $this->redirect(route('rendabiliteit.google.koppelen'));
                        }),
                    $this->controleerActie(KoppelingInstellingen::GOOGLE),
                ])
                ->description('Google Cloud: OAuth-client met scope adwords en een refresh token. De developer token staat in het API-centrum van een Ads-manageraccount.')
                ->schema([
                    Toggle::make('google_aan')->label('Elke nacht ophalen'),
                    TextInput::make('google_customer_id')->label('Klant-ID')->helperText('Het account met de campagnes, bijvoorbeeld 123-456-7890.'),
                    TextInput::make('google_login_customer_id')->label('Manageraccount-ID')->helperText('Alleen als je via een manageraccount toegang hebt.'),
                    TextInput::make('google_client_id')->label('OAuth client-ID')
                        ->helperText(fn () => new HtmlString('OAuth-client van het type "Webapplicatie". Zet bij Geautoriseerde omleidings-URI\'s: <code style="user-select: all;">'.e(GoogleKoppelingController::terugUrl()).'</code>')),
                    $this->geheimVeld('google_client_secret', 'OAuth client secret'),
                    $this->geheimVeld('google_refresh_token', 'Refresh token')
                        ->hint('Of klik op "Koppel met Google".'),
                    $this->geheimVeld('google_developer_token', 'Developer token'),
                    $this->standVeld(KoppelingInstellingen::GOOGLE),
                    $this->promptVeld(KoppelingInstellingen::GOOGLE),
                ]),
            Section::make('Bol')
                ->headerActions([$this->controleerActie(KoppelingInstellingen::BOL)])
                ->description('Partnerplatform → Client credentials voor de Advertising API (niet die van de Retailer API). Bol levert hoogstens 30 dagen terug.')
                ->schema([
                    Toggle::make('bol_aan')->label('Elke nacht ophalen'),
                    TextInput::make('bol_client_id')->label('Advertiser-ID'),
                    $this->geheimVeld('bol_client_secret', 'Advertiser secret'),
                    $this->standVeld(KoppelingInstellingen::BOL),
                    $this->promptVeld(KoppelingInstellingen::BOL),
                ]),
        ]);
    }

    public function submit(): void
    {
        $this->bewaar();

        Notification::make()->title('De koppelingen zijn opgeslagen')->success()->send();
    }

    public function haalOp(?CarbonImmutable $van = null, ?string $platform = null): void
    {
        $this->bewaar();

        if ($van !== null) {
            @set_time_limit(900);
        }

        $uitkomst = app(AdvertentieKoppelingen::class)->haalOp($van, null, $platform);

        if ($uitkomst === []) {
            Notification::make()->title('Geen koppeling aan of volledig ingevuld')->warning()->send();

            return;
        }

        foreach ($uitkomst as $platform => $stand) {
            $melding = Notification::make()->title($platform);

            $stand['gelukt']
                ? $melding->body("{$stand['dagen']} dagen, € ".number_format($stand['totaal'], 2, ',', '.'))->success()
                : $melding->body($stand['fout'])->danger()->persistent();

            $melding->send();
        }

        $this->redirect(static::getUrl());
    }

    private function bewaar(): void
    {
        $data = $this->form->getState();

        foreach ([KoppelingInstellingen::META, KoppelingInstellingen::GOOGLE, KoppelingInstellingen::BOL] as $koppeling) {
            KoppelingInstellingen::zetAan($koppeling, (bool) ($data["{$koppeling}_aan"] ?? false));
        }

        foreach (self::WAARDEN as $veld) {
            KoppelingInstellingen::zetWaarde($veld, $data[$veld] ?? null);
        }

        foreach (self::GEHEIMEN as $veld) {
            KoppelingInstellingen::zetGeheim($veld, $data[$veld] ?? null);
        }
    }

    private function geheimVeld(string $veld, string $label): TextInput
    {
        return TextInput::make($veld)
            ->label($label)
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->helperText(fn () => KoppelingInstellingen::geheim($veld) !== null ? 'Opgeslagen. Leeg laten houdt het ongewijzigd.' : null);
    }

    private function controleerActie(string $koppeling): Action
    {
        return Action::make("controleer_{$koppeling}")
            ->label('Controleer verbinding')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->size('sm')
            ->action(function () use ($koppeling) {
                // Eerst bewaren, zodat net ingevulde gegevens meetellen.
                $this->bewaar();

                $uitkomst = app(AdvertentieKoppelingen::class)->controleer($koppeling);
                $melding = Notification::make()->title($uitkomst['gelukt'] ? 'Verbinding werkt' : 'Verbinding werkt niet')->body($uitkomst['melding']);

                $uitkomst['gelukt'] ? $melding->success() : $melding->danger()->persistent();
                $melding->send();
            });
    }

    private function promptVeld(string $koppeling): Placeholder
    {
        return Placeholder::make("{$koppeling}_prompt")
            ->label('Laten fixen met AI in Chrome')
            ->content(function () use ($koppeling) {
                $ingevuld = [];

                foreach (KoppelingPrompt::VELDEN[$koppeling] as $veld => [, $geheim]) {
                    $ingevuld[$veld] = $geheim
                        ? KoppelingInstellingen::geheim($veld) !== null
                        : KoppelingInstellingen::waarde($veld) !== null;
                }

                $prompt = KoppelingPrompt::voor(
                    $koppeling,
                    static::getUrl(),
                    $ingevuld,
                    KoppelingInstellingen::stand($koppeling),
                    KoppelingInstellingen::aan($koppeling),
                );

                return new HtmlString(view('dashed-ecommerce-rendabiliteit::koppelingen.prompt', ['prompt' => $prompt])->render());
            });
    }

    private function standVeld(string $koppeling): Placeholder
    {
        return Placeholder::make("{$koppeling}_stand")
            ->label('Laatste keer')
            ->content(function () use ($koppeling) {
                $stand = KoppelingInstellingen::stand($koppeling);

                if (! $stand) {
                    return 'Nog niet opgehaald.';
                }

                $moment = Carbon::parse($stand['at'])->timezone(config('app.timezone'))->format('d-m-Y H:i');

                return $stand['gelukt']
                    ? "{$moment}: {$stand['dagen']} dagen, € ".number_format($stand['totaal'], 2, ',', '.')
                    : "{$moment}: mislukt. {$stand['fout']}";
            });
    }
}
