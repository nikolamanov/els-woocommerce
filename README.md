# ELS for WooCommerce

[Преземи ја најновата installable ZIP верзија](https://github.com/nikolamanov/els-woocommerce/releases/latest/download/els-woocommerce.zip)

Open-source community plugin под лиценца GPL-2.0-or-later. Ова не е официјален производ на ELS; за користење е потребен активен ELS профил и API податоци добиени директно од ELS.

Готов WordPress/WooCommerce plugin за креирање пратки во ELS, без фиксни податоци за конкретна компанија.

## Инсталација

1. Направете ZIP од папката `els-woocommerce` или употребете го приложениот `els-woocommerce.zip`.
2. Во WordPress отворете **Plugins → Add New → Upload Plugin**.
3. Активирајте **ELS for WooCommerce**.
4. Отворете **WooCommerce → ELS Integration**.
5. Внесете API credentials, точниот **Тип на листа**, податоците за испраќачот и мапата на колоните.
6. Зачувајте, тестирајте ја конекцијата, па вклучете го plugin-от.

## Како работи

- Нарачката автоматски се става во queue кога ќе добие еден од избраните статуси.
- Од order actions може рачно да се избере **Send/retry shipment to ELS**.
- Во самата нарачка има директно копче **Create shipment in ELS** за постоечки нарачки кои сè уште немаат ELS пратка.
- Billing/shipping city може да се избере од searchable ELS листа со 1.782 точни места; за стара нарачка местото се избира во **ELS shipment** панелот пред рачно праќање.
- Поддржани се classic и Checkout Block полиња, со background нормализација меѓу кирилица и латиница (на пр. `Kocani` → `Кочани`).
- По успешно ELS креирање, tracking бројот се запишува и во подеслив order meta key (стандардно `_tracking_number`) за компатибилност со постоечки Tracking Number полиња.
- Ако сајтот нема сопствен `add_tracking_and_cfma_fields()` snippet, plugin-от автоматски прикажува и зачувува **Tracking Number** и **CFMA Number** полиња во нарачката.
- Completed order email-от и My Account деталите прикажуваат директен tracking линк; ако пратката се креира по completed email-от, се испраќа customer-note email со линкот.
- Листата со места се активира само кога checkout државата е Македонија / Северна Македонија (`MK`); за други држави „Град“ останува слободно текстуално поле.
- Од листата на нарачки може масовно да се избере **Queue shipment creation in ELS**.
- По успех се зачувуваат ELS serial/tracking број, tracking URL, испратница и label URL.
- Ако веќе постои ELS serial или tracking број, plugin-от не ја испраќа нарачката повторно.

## Кој ја плаќа поштарината

Во **WooCommerce → ELS Integration → Shipment import**, избери **Sender** или **Customer (recipient)** во **Who pays ELS shipping?**.

- Со **Sender** се користи досегашниот тип на ELS листа. За нарачка со плаќање при достава, откупот е целиот износ од WooCommerce. На пример, 1.000 денари за производите и 160 денари поштарина се праќаат како 1.160 денари откуп.
- Со **Customer (recipient)** внеси го точниот назив на посебниот ELS тип на листа каде што примачот ја плаќа поштарината. За `ЛИСТА ПАКЕТ-ПП`, остави го разделувачот за купувач на **Automatic** и полето **Customer-paid column mapping** празно. Plugin-от го користи вградениот формат од ELS урнекот, со `место smart detect` и табулатор. За друг тип можеш да внесеш посебна мапа и разделувач. За истиот пример, откупот е 1.000 денари, а ELS ја наплаќа својата поштарина од примачот.

Подесувањето важи при креирање пратка во ELS. Ако ELS му наплаќа поштарина на купувачот, постави ги цените за достава во WooCommerce соодветно за да избегнеш двојна наплата кај однапред платени нарачки.

## Мапа на колони

Форматот е `Точно ELS име на колона=извор`. Редоследот на линиите е редоследот во CSV payload-от. Бидејќи секој ELS „Тип на листа“ може да биде различен, копирајте ги заглавијата од Excel/CSV урнекот извезен од ELS порталот.

Достапните извори се прикажани во самата settings страница. За custom order meta користете `meta:ime_na_meta`, а за фиксна вредност `static:вредност`.

## Технички белешки

- Default API URL: `https://api.els.mk`
- Default login endpoint: `/users/login`
- Default bulk endpoint: `/orders/bulk`
- Credentials никогаш не се логираат.
- Поддржани се WooCommerce HPOS и Action Scheduler.
- Достапен е filter `els_wc_column_value` за дополнителни custom извори.
