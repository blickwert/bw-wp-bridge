"""Test der Mehr-Websites-Auswahl im Client (tools/wp_bridge.py). Aufruf: python3 tests/test_client_sites.py"""
import importlib.util, os, subprocess, sys, unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(HERE, "..", "tools", "wp_bridge.py")
spec = importlib.util.spec_from_file_location("wp_bridge", SCRIPT)
wb = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wb)

ENV2 = {
    "WP_SOULDATE_URL": "https://a.test/wp/souldate", "WP_SOULDATE_USER": "u1", "WP_SOULDATE_APP_PASSWORD": "p1",
    "WP_SOULDATE_BRIDGE_KEY": "bwk_1", "WP_SOULDATE_BRIDGE_SITE": "11111111",
    "WP_APPA_URL": "https://a.test/wp/appa", "WP_APPA_USER": "u2", "WP_APPA_APP_PASSWORD": "p2",
}


class Sites(unittest.TestCase):
    def test_legacy_single_site(self):
        s = wb.load_sites({"WP_URL": "https://x.test", "WP_USER": "u", "WP_APP_PASSWORD": "p", "WP_BRIDGE_KEY": "k"})
        self.assertEqual(list(s), ["default"])
        self.assertEqual(s["default"]["key"], "k")
        self.assertEqual(wb.pick_site(s)[0], "default")

    def test_named_sets_and_fields(self):
        s = wb.load_sites(ENV2)
        self.assertEqual(sorted(s), ["appa", "souldate"])
        self.assertEqual(s["souldate"]["key"], "bwk_1")
        self.assertEqual(s["souldate"]["site"], "11111111")
        self.assertIsNone(s["appa"]["key"])

    def test_names_with_underscore_and_digits(self):
        s = wb.load_sites({"WP_MY_SITE2_URL": "https://m.test", "WP_MY_SITE2_USER": "u", "WP_MY_SITE2_APP_PASSWORD": "p"})
        self.assertEqual(list(s), ["my_site2"])

    def test_global_variables_are_not_sites(self):
        s = wb.load_sites({"WP_TIMEOUT": "5", "WP_BRIDGE_KEY": "k", "WP_BRIDGE_SITE": "x", "WP_BASIC_AUTH": "a:b", "WP_SITE": "souldate"})
        self.assertEqual(s, {})

    def test_several_sites_need_a_choice(self):
        s = wb.load_sites(ENV2)
        with self.assertRaises(SystemExit) as e:
            wb.pick_site(s, None)
        self.assertIn("Mehrere Websites", str(e.exception))
        self.assertEqual(wb.pick_site(s, "SouldATE")[0], "souldate")

    def test_unknown_site(self):
        with self.assertRaises(SystemExit) as e:
            wb.pick_site(wb.load_sites(ENV2), "gibtsnicht")
        self.assertIn("appa", str(e.exception))

    def test_legacy_and_named_together_need_a_choice(self):
        env = dict(ENV2, WP_URL="https://x.test", WP_USER="u", WP_APP_PASSWORD="p")
        s = wb.load_sites(env)
        self.assertIn("default", s)
        with self.assertRaises(SystemExit):
            wb.pick_site(s, None)

    def test_client_uses_the_chosen_set(self):
        name, cfg = wb.pick_site(wb.load_sites(ENV2), "souldate")
        c = wb.Client(cfg, name)
        self.assertEqual((c.base, c.key, c.expect_site), ("https://a.test/wp/souldate", "bwk_1", "11111111"))

    def test_client_reports_missing_password(self):
        with self.assertRaises(SystemExit) as e:
            wb.Client({"url": "https://a.test", "user": "u"}, "appa")
        self.assertIn("WP_APPA_APP_PASSWORD", str(e.exception))


class Cli(unittest.TestCase):
    def run_cli(self, env, *args):
        full = {k: v for k, v in os.environ.items() if not k.startswith("WP_")}
        full.update(env)
        return subprocess.run([sys.executable, SCRIPT, *args], capture_output=True, text=True, env=full)

    def test_sites_lists_without_secrets(self):
        r = self.run_cli(ENV2, "sites")
        self.assertEqual(r.returncode, 0)
        self.assertIn("souldate", r.stdout)
        self.assertIn("https://a.test/wp/appa", r.stdout)
        for secret in ("p1", "p2", "bwk_1"):
            self.assertNotIn(secret, r.stdout + r.stderr)

    def test_command_without_choice_sends_nothing(self):
        r = self.run_cli(ENV2, "get", "wp/v2/pages")
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("Mehrere Websites", r.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=1)
