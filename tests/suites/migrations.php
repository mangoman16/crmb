<?php
/**
 * The migrations that move data, run against data.
 *
 * Every other suite gets its database by applying all sixteen migrations to an
 * empty one, so the statements that carry something across have never touched a
 * row: a tariff's price into tariff_rates, its discount onto every enrolment
 * that was getting it, an address onto every child. "Nobody's next invoice
 * changes" and "nobody is signed out" were claims with nothing behind them, and
 * they are the two claims a trainer is trusting with her families' money.
 *
 * tests/migration-data.php builds a portal as it stood before 015, applies the
 * rest, and prints what it finds. This reads that and holds it to the promise.
 *
 * It runs in another process against its own SQLite file, whichever driver the
 * rest of the run is using: the database this suite is connected to has every
 * migration applied already, and this has to stop half way. On MySQL that
 * leaves the dialect of these particular statements proven only by their having
 * applied during boot, which is recorded in the footer rather than glossed over.
 */

$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tests/migration-data.php') . ' 014 2>&1', $out, $code);
$raw = implode("\n", $out);
is_same(0, $code, 'the migrations apply in two halves with data in between');
$after = json_decode($raw, true);
if (!is_array($after)) {
    ok(false, 'the two-half run printed a result: ' . mb_substr($raw, 0, 300));
    return;
}
if (test_driver() !== 'sqlite')
    test_unsupported(array_merge(test_unsupported(),
        ['the data moved by migrations 015 and 016 (checked on sqlite, whatever driver this run used)']));

$ids = $after['ids'];
$by = fn(array $rows, string $key, int $id) => array_values(array_filter($rows, fn($r) => (int)$r[$key] === $id));

// ---------------------------------------------------------------------------
case_('015 turns every tariff price into the first of its rates');
is_same(2, count($after['rates']), 'one rate per tariff, and not one more');
is_same([['tariff_id' => $ids['giving'], 'interval_months' => 1, 'price_cents' => 4500]],
        $by($after['rates'], 'tariff_id', $ids['giving']), 'the monthly one at its monthly price');
is_same([['tariff_id' => $ids['plain'], 'interval_months' => 6, 'price_cents' => 24000]],
        $by($after['rates'], 'tariff_id', $ids['plain']), 'and the half-yearly one at its own interval');
// If this were wrong nobody would notice until the next billing run charged
// everybody the wrong amount, so it is worth saying out loud.
foreach ($after['rates'] as $rate)
    ok((int)$rate['price_cents'] > 0, 'no tariff came out of the migration free');

case_('And the welcome discount becomes a template, only where there was one');
is_same(1, count($after['templates']), 'one template, from the one tariff that was giving a discount');
$template = $after['templates'][0];
is_same($ids['giving'], (int)$template['tariff_id'], 'on that tariff');
is_same(3, (int)$template['months'], 'for the three months it ran');
is_same('percent', $template['kind'], 'in the shape it had');
is_same(30, (int)$template['value'], 'at the size it was');
is_same([], $by($after['templates'], 'tariff_id', $ids['plain']), 'and a tariff giving nothing gets no template');

case_('Every family already getting a discount keeps it, to the cent');
$enrolments = [];
foreach ($after['enrolments'] as $row) $enrolments[(int)$row['student_id']] = $row;
$kept = $enrolments[$ids['on_account']];
is_same(3, (int)$kept['discount_months'], 'the same three months');
is_same('percent', $kept['discount_kind'], 'the same shape');
is_same(30, (int)$kept['discount_value'], 'the same thirty per cent');
is_same('Willkommensrabatt', $kept['discount_note'], 'under a name that can go on an invoice');
is_same(0, (int)$kept['interval_months'], 'and on the tariff’s usual interval, which is what it was on before');

case_('And nobody else is given one by an UPDATE that forgot its WHERE');
$untouched = $enrolments[$ids['by_contact']];
is_same(0, (int)$untouched['discount_months'], 'a family on a tariff that gave nothing gets nothing');
is_same(0, (int)$untouched['discount_value'], 'at no value');
is_same('', $untouched['discount_note'], 'and no name');
$noTariff = $enrolments[$ids['neither']];
is_same(0, (int)$noTariff['discount_months'], 'nor does an enrolment on no tariff at all');
is_same('', $noTariff['discount_note'], 'which would otherwise be given a name for a gift it never got');
is_same(3000, (int)$noTariff['price_cents'], 'whose agreed price of its own is still there');

case_('The columns the code no longer reads are gone');
// Left behind they would be a second answer waiting to be believed: the rows
// above are the first, and two answers is how the wrong one gets used.
foreach (['price_cents', 'discount_months', 'discount_kind', 'discount_value'] as $dropped)
    ok(!in_array($dropped, $after['tariff_columns'], true), 'tariffs.'.$dropped.' was dropped');
foreach (['name', 'interval_months', 'due_day', 'grace_days', 'first_period'] as $kept)
    ok(in_array($kept, $after['tariff_columns'], true), 'and tariffs.'.$kept.' was not');

// ---------------------------------------------------------------------------
case_('016 fills in where the portal was already writing to each child');
$students = [];
foreach ($after['students'] as $row) $students[(int)$row['id']] = $row;
// The account wins over the contact: that is the address they actually sign in
// with, and a contact's address would have been a second one nobody chose.
is_same('familie@beispiel.test', $students[$ids['on_account']]['email'],
        'a child on an account gets the account’s address, not their grandmother’s');
is_same('maria@beispiel.test', $students[$ids['by_contact']]['email'],
        'a child with no account gets the standard contact’s');
ok($students[$ids['by_contact']]['email'] !== 'opa@beispiel.test',
   'and not the other contact’s, which is on the same child and is not the standard one');
is_same('', $students[$ids['neither']]['email'],
        'and a child with nowhere to write comes out empty rather than wrong');

case_('Nobody is signed out by it');
is_same($ids['on_account'], (int)$students[$ids['on_account']]['id'], 'the child is still there');
ok($students[$ids['on_account']]['account_id'] !== null, 'still on their account');
is_same(null, $students[$ids['by_contact']]['account_id'], 'and one who had none still has none');
