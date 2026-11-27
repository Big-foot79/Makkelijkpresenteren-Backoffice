# AI-agents en de MCP-server

De backoffice heeft een eigen MCP-server (via het officiële pakket `laravel/mcp`). Een AI-agent
zoals Claude kan daarmee de backoffice lezen en, in latere fases, taken uitvoeren.

## Uitgangspunten

1. **Eén bron van logica.** Tools roepen dezelfde acties aan als het beheer en de queue.
2. **Een agent is een gebruiker.** Rol *AI-agent*, eigen token, kan niet inloggen in het beheer.
3. **Alles wordt gelogd.** Wijzigingen via MCP staan in de wijzigingshistorie met de agent als actor
   en kanaal `mcp`.
4. **Voorstel vóór uitvoering.** Acties met gevolgen worden een taak *ter goedkeuring*.
5. **Eerst lezen, dan schrijven.** Fase 1 bevat alleen leestools.

## Agent aanmaken

```sh
php artisan backoffice:agent "Claude" claude@agents.local
```

Het token wordt één keer getoond. Opnieuw draaien met hetzelfde e-mailadres geeft een extra token.
Tokens intrekken: in het beheer onder *Systeem → Gebruikers* (agent uitzetten), of
`php artisan tinker` → `User::whereEmail('claude@agents.local')->first()->tokens()->delete()`.

## Verbinden

- URL: `https://<domein>/mcp` (Streamable HTTP)
- Header: `Authorization: Bearer <token>`

Voorbeeld voor een MCP-client die een JSON-configuratie gebruikt:

```json
{
  "mcpServers": {
    "backoffice": {
      "type": "http",
      "url": "https://backoffice.makkelijkpresenteren.nl/mcp",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

Lokaal testen met de MCP Inspector:

```sh
php artisan mcp:inspector mcp
```

## Tools

| Tool | Soort | Wat het doet |
|---|---|---|
| `backoffice-status` | Lezen | Overzicht: open taken per soort, runs van de laatste 24 uur, en per leverancier hoe vers voorraad en catalogus zijn |
| `list-open-tasks` | Lezen | Open taken, optioneel gefilterd op soort |
| `list-job-runs` | Lezen | Recente runs, optioneel alleen mislukte of van één type, met samenvatting |
| `search-supplier-items` | Lezen | Leveranciersartikelen zoeken op EAN, artikelnummer, fabrikantnummer, merk of omschrijving; met inkoopprijs, adviesprijs, voorraad per leverancier en de beschikbaarheid zoals op de site |
| `search-products` | Lezen | Webshopproducten zoeken op EAN, SKU, merk of naam; met webshopprijs, laagste inkoop, marge, beschikbaarheid en wat er nu in WooCommerce staat |

## Gepland

| Tool | Soort | Fase |
|---|---|---|
| Product zoeken (EAN, naam, merk; prijsopbouw, voorraad per leverancier, concurrentprijs) | Lezen | 3 |
| Marge-overzicht | Lezen | 3 |
| Verrijking opnieuw starten | Uitvoeren | 4 |
| Producttekst herschrijven | Ter goedkeuring | 4 |
| Bid-aanvraag opstellen | Ter goedkeuring | 5 |
| Taak afhandelen (conflict overnemen of terugzetten) | Ter goedkeuring | 3 |
