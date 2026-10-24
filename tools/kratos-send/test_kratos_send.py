"""Tests for kratos_send (run: python -m unittest discover -s tools/kratos-send)."""

import ipaddress
import random
import unittest

import kratos_send

# Shared with core/tests/Unit/RequestSignerTest.php — both must agree.
SECRET = "krs_test_vector_secret_do_not_use"
BODY = b'{"event_type":"authentication_failure","severity":"medium"}'
EXPECTED = "v1=eba2a321c0723800bff9b21f8252247d8438e4195cf19711f3fe105aa00f2b1d"

DOCUMENTATION_NETWORKS = [ipaddress.ip_network(n) for n in ("192.0.2.0/24", "198.51.100.0/24", "203.0.113.0/24")]


class SigningTest(unittest.TestCase):
    def test_shared_vector_matches_php_implementation(self) -> None:
        self.assertEqual(EXPECTED, kratos_send.sign(SECRET, "POST", "/api/v1/ingest/events", "1792771200", BODY))

    def test_method_is_normalised(self) -> None:
        self.assertEqual(
            kratos_send.sign(SECRET, "post", "/p", "1", b""),
            kratos_send.sign(SECRET, "POST", "/p", "1", b""),
        )


class SimulationTest(unittest.TestCase):
    def events(self, scenario: str, count: int) -> list[dict]:
        return list(kratos_send.scenario(scenario, count, random.Random(7), asset=None))

    def test_scenarios_produce_the_requested_number_of_events(self) -> None:
        for name in ("baseline", "mixed"):
            self.assertEqual(50, len(self.events(name, 50)), name)

    def test_all_addresses_are_documentation_ranges(self) -> None:
        for event in self.events("mixed", 300):
            ip = event["metadata"].get("source_ip")
            if ip:
                address = ipaddress.ip_address(ip)
                self.assertTrue(any(address in net for net in DOCUMENTATION_NETWORKS), ip)

    def test_events_are_labelled_synthetic_and_carry_idempotency_keys(self) -> None:
        events = self.events("baseline", 20)
        self.assertTrue(all(e["metadata"]["synthetic"] is True for e in events))
        self.assertEqual(20, len({e["idempotency_key"] for e in events}))

    def test_brute_force_comes_from_one_address_and_ends_in_lockout(self) -> None:
        events = self.events("brute-force", 30)
        attackers = {e["metadata"]["source_ip"] for e in events}
        self.assertEqual(1, len(attackers))
        self.assertEqual("account_lockout", events[-1]["event_type"])

    def test_events_match_the_ingestion_schema_fields(self) -> None:
        allowed = {"event_type", "source", "severity", "timestamp", "metadata", "asset", "idempotency_key"}
        for event in self.events("mixed", 100):
            self.assertLessEqual(set(event), allowed)
            self.assertIn(event["severity"], {"info", "low", "medium", "high", "critical"})
            self.assertTrue(event["timestamp"].endswith("Z"))


if __name__ == "__main__":
    unittest.main()
