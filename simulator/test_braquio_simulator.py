import unittest
from unittest.mock import patch

from braquio_simulator import DispositivoREX, FACTOR_GOTEO


class DispositivoREXTest(unittest.TestCase):
    def test_demo_modes_and_remaining_time_match_the_packet(self):
        for mode, volume in [("NORMAL_GOTEO", 500.0), ("MICRO_GOTEO", 250.0)]:
            with self.subTest(mode=mode), patch("braquio_simulator.time.time", return_value=1791200000.123):
                packet = DispositivoREX("cama-01", mode, volume).generar_lectura("normal")
            self.assertEqual(packet["modo"], mode)
            self.assertEqual(packet["volRestante"], volume)
            self.assertEqual(packet["gotasPorMin"], 32.5)
            self.assertEqual(packet["tiempoRestante"], int(volume * FACTOR_GOTEO[mode] / 32.5))
            self.assertEqual(packet["timestamp"], 1791200000123)

    def test_scenarios_cross_the_backend_alert_thresholds(self):
        cases = {"normal": (32.5, 500.0), "lento": (19.9, 500.0),
                 "rapido": (60.1, 500.0), "fin-bolsa": (32.5, 45.2),
                 "combinado": (19.9, 45.2)}
        for scenario, (drops, volume) in cases.items():
            with self.subTest(scenario=scenario), patch("braquio_simulator.time.time", return_value=1000):
                packet = DispositivoREX("cama-01", "NORMAL_GOTEO", 500.0).generar_lectura(scenario)
            self.assertEqual(packet["gotasPorMin"], drops)
            self.assertEqual(packet["volRestante"], volume)

    def test_random_anomalies_reach_the_new_thresholds(self):
        for index, expected in [(0, 19.9), (1, 60.1)]:
            with self.subTest(index=index), \
                    patch("braquio_simulator.time.time", return_value=1000), \
                    patch("braquio_simulator.random.random", return_value=0), \
                    patch("braquio_simulator.random.uniform", side_effect=lambda low, high: high if high < 20 else low), \
                    patch("braquio_simulator.random.choice", side_effect=lambda values: values[index]):
                packet = DispositivoREX("cama-01", "NORMAL_GOTEO", 500.0).generar_lectura()
            self.assertEqual(packet["gotasPorMin"], expected)

    def test_bag_replacement_keeps_the_demo_session_mode(self):
        with patch("braquio_simulator.time.time", side_effect=[1000, 1060]):
            packet = DispositivoREX("cama-02", "MICRO_GOTEO", 0.001).generar_lectura("normal")
        self.assertEqual(packet["modo"], "MICRO_GOTEO")
        self.assertGreater(packet["volRestante"], 0)
        self.assertEqual(packet["tiempoRestante"], int(packet["volRestante"] * 60 / 32.5))


if __name__ == "__main__":
    unittest.main()
