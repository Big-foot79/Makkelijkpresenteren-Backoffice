# ALSO Benelux: XML-koppeling (samenvatting van de XML Guideline v2.40)

Bron: *XML 2.40 Manual ALSO Benelux*, 21-06-2024. Dit is het uitgangspunt voor fase 6 (inkoop).
Technische vragen gaan naar edi-support.nl@also.com.

## Berichten

| Bericht | Richting | Wat het doet |
|---|---|---|
| PriceAvailability (webservice) | Wij → ALSO, direct antwoord | Actuele prijs en voorraad op het moment zelf. Het formaat staat niet in deze handleiding: dat document moeten we nog opvragen. |
| StockInfo | ALSO → FTP | De voorraad van alle artikelen uit de prijscatalogus, elk uur bijgewerkt. Per regel: ALSO-artikelnummer, voorraad (ATP) en de volgende leverdatum. |
| Order | Wij → ALSO | Een inkooporder, eventueel met een ander afleveradres. "Distribution" is levering rechtstreeks aan de klant (dropship) met eigen pakbonteksten. Een projectnummer (deal-id van de fabrikant of contractnummer van ALSO) kan op de order of per regel. |
| ReceiptAcknowledgement / Exception | ALSO → wij | Of de order technisch geldig is. Bij Exception volgt een foutmelding per veld. |
| OrderResponse | ALSO → wij | De orderbevestiging met status (Open, InProcess of Done; NotShipped, PartialShipped of Shipped; Rejected), de netto prijs per regel en de geplande leverdata. De datum 20501231 met aantal 0 betekent dat de leverdatum onbekend is. |
| OrderChange | Wij → ALSO | Aantal of datum wijzigen, of een regel toevoegen. |
| Delivery | ALSO → wij | De verzendmelding na uitlevering, met geleverde aantallen, serienummers en MAC-adressen, de vervoerder en een track & trace-link. |
| Invoice | ALSO → wij | De elektronische factuur met netto prijs per regel, inclusief toeslagen. Basis voor de factuurcontrole. |
| Remittance Advice, Return Notification | | Betaaladvies en retouren. Alleen gebruiken als we dat met ALSO afspreken. |

## Techniek

- **Verzenden:** XML (UTF-8) via een HTTPS POST naar
  - test: `https://b2b-test.also.com/invoke/ActDelivery_HTTP.Inbound/receiveXML`
  - productie: `https://b2b.also.com/invoke/ActDelivery_HTTP.Inbound/receiveXML`
- **Inloggen:** HTTP Basic Auth met een gebruikersnaam en wachtwoord van ALSO, of met een clientcertificaat.
- **Berichten van ALSO:** ALSO doet een HTTPS POST naar een URL van ons, met Basic Auth als we dat willen, en verwacht een HTTP 200 terug. Hiervoor komt een webhook-route in de backoffice, net als bij WooCommerce.
- **Afzender en ontvanger:**
  - Ontvanger: het GLN van ALSO Nederland, 4005922024208.
  - Afzender: ons GLN of DUNS-nummer, of anders ons ALSO-klantnummer.
- **Verplichte orderregelvelden:** het ALSO-artikelnummer en de netto prijs uit de prijscatalogus (`NetCustomer`), tenzij er een contractnummer op staat.

## Nog op te vragen bij ALSO

1. Een test- en productieaccount voor de HTTPS-gateway.
2. De specificatie van de PriceAvailability-webservice.
3. Of het StockInfo-bestand van elk uur al op onze FTP staat. Het past mogelijk bij een van de acht bestanden die we daar zagen.
4. Het formaat voor het projectnummer bij bids.
5. Of ALSO ook toestaat dat berichten naar ons op poort 443 met Basic Auth binnenkomen.
