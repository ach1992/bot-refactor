#!/usr/bin/env python3
"""Offline checks for reproducible, cache-visible deployment timestamps."""
from __future__ import annotations

import calendar
import sys
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import package_post_v9 as package


class ReleaseTimestampTests(unittest.TestCase):
    def test_utc_even_second_is_deterministic(self):
        epoch = calendar.timegm((2026, 10, 15, 15, 30, 45))
        expected = (2026, 10, 15, 15, 30, 44)
        self.assertEqual(package.zip_timestamp_utc(epoch), expected)
        self.assertEqual(package.zip_timestamp_utc(epoch), expected)
        self.assertNotEqual(expected, (1980, 1, 1, 0, 0, 0))

    def test_old_or_out_of_range_epoch_fails_closed(self):
        for epoch in (0, calendar.timegm((1979, 12, 31, 23, 59, 59)),
                      calendar.timegm((2108, 1, 1, 0, 0, 0))):
            with self.subTest(epoch=epoch), self.assertRaises(RuntimeError):
                package.zip_timestamp_utc(epoch)

    def test_new_release_differs_from_historical_fixed_mtime(self):
        old = (1980, 1, 1, 0, 0, 0)
        future = package.zip_timestamp_utc(
            calendar.timegm((2026, 10, 15, 15, 31, 0)))
        self.assertNotEqual(old, future)
        self.assertEqual(zipfile.ZipInfo("bot.php", future).date_time, future)

    def test_timestamp_uses_exact_committed_head(self):
        with patch.object(package, "git", return_value=b"1792078245\n") as git:
            stamp = package.committed_zip_timestamp("f" * 40)
            git.assert_called_once_with(
                "show", "-s", "--format=%ct", "f" * 40)
        self.assertEqual(stamp, package.zip_timestamp_utc(1792078245))


if __name__ == "__main__":
    unittest.main()
