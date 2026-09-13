<?php

use App\Services\Upstream\AddressPin;

it('refuses to build a pin for a url the address policy rejects', function () {
    expect(AddressPin::for('http://vault.internal/secret'))->toBeNull()
        ->and(AddressPin::for('http://127.0.0.1/'))->toBeNull()
        ->and(AddressPin::for('ftp://repo.test/x'))->toBeNull();
});

it('defaults the pinned port to the scheme and honours an explicit one', function () {
    $this->resolveHostTo('repo.test', ['203.0.113.10']);

    expect(AddressPin::for('https://repo.test/a')?->port)->toBe(443)
        ->and(AddressPin::for('http://repo.test/a')?->port)->toBe(80)
        ->and(AddressPin::for('https://repo.test:8443/a')?->port)->toBe(8443);
});

it('emits no curl pin for an ip literal, because there is no lookup to pin', function () {
    $pin = AddressPin::for('https://93.184.216.34/x');

    expect($pin)->not->toBeNull()
        ->and($pin->curlOptions())->toBe([])
        ->and($pin->permits('93.184.216.34:443'))->toBeTrue();
});

it('permits only the addresses that were judged', function () {
    $this->resolveHostTo('repo.test', ['203.0.113.10', '198.51.100.7']);
    $pin = AddressPin::for('https://repo.test/a');

    expect($pin->permits('203.0.113.10:443'))->toBeTrue()
        ->and($pin->permits('198.51.100.7:443'))->toBeTrue()
        // The rebinding target: a second DNS answer the check never saw.
        ->and($pin->permits('169.254.169.254:443'))->toBeFalse()
        ->and($pin->permits('127.0.0.1:443'))->toBeFalse()
        ->and($pin->permits('10.0.0.5:80'))->toBeFalse();
});

it('refuses a peer it cannot read rather than assuming it is fine', function () {
    $this->resolveHostTo('repo.test', ['203.0.113.10']);
    $pin = AddressPin::for('https://repo.test/a');

    expect($pin->permits(null))->toBeFalse()
        ->and($pin->permits(''))->toBeFalse()
        ->and($pin->permits('not-an-address'))->toBeFalse()
        ->and($pin->permits('203.0.113.10.evil.test:443'))->toBeFalse();
});

it('matches an ipv6 peer in every shape the transports report it', function () {
    $this->resolveHostTo('repo.test', ['2606:2800:220:1:248:1893:25c8:1946']);
    $pin = AddressPin::for('https://repo.test/a');

    expect($pin->permits('[2606:2800:220:1:248:1893:25c8:1946]:443'))->toBeTrue()
        ->and($pin->permits('2606:2800:220:1:248:1893:25c8:1946'))->toBeTrue()
        ->and($pin->permits('[::1]:443'))->toBeFalse();
});

it('folds an ipv4-mapped peer down to the ipv4 that was judged', function () {
    $this->resolveHostTo('repo.test', ['203.0.113.10']);
    $pin = AddressPin::for('https://repo.test/a');

    // A dual-stack socket can report the v4 peer in its ::ffff: form; refusing that
    // would break legitimate fetches without denying an attacker anything.
    expect($pin->permits('[::ffff:203.0.113.10]:443'))->toBeTrue()
        ->and($pin->permits('[::ffff:169.254.169.254]:443'))->toBeFalse();
});

it('parses the peer shape a real socket actually reports', function () {
    // The guard is worth exactly as much as its parsing of what PHP emits for a live
    // connection, so the shape under test comes from a real socket rather than from a
    // hand-written string that could quietly drift from reality.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $client = stream_socket_client('tcp://'.stream_socket_get_name($server, false), $errno, $errstr, 5);
    expect($client)->not->toBeFalse();

    $peer = (string) stream_socket_get_name($client, true);
    expect($peer)->toStartWith('127.0.0.1:');

    $pin = AddressPin::for('https://93.184.216.34/x');

    // Same shape, judged address → permitted; same shape, a different address → refused.
    expect($pin->permits(str_replace('127.0.0.1', '93.184.216.34', $peer)))->toBeTrue()
        ->and($pin->permits($peer))->toBeFalse();

    fclose($client);
    fclose($server);
});
