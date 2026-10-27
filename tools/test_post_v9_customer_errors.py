#!/usr/bin/env python3
"""Customer-visible diagnostic privacy guard for the active post-V9 runtime.

Static guard catches ONLY first-argument literal/resolved-catalog template regressions on
customer-only surfaces. It does not prove full customer or administrator privacy.
Mixed finance customer/admin branches are executed using owned MariaDB and
synthetic sensitive provider errors in test_post_v9_finance_error_privacy.py.
Authorized administration/configuration screens are intentionally excluded.
"""
from __future__ import annotations

import json
import re
from pathlib import Path
import catalog_source

ROOT = Path(__file__).resolve().parents[1]
CUSTOMER_SURFACES = (
    "src/Finance/Routes/PrePayment.php",
    "src/Finance/Routes/RenewalSelection.php",
    "src/Finance/Routes/RenewalPlan.php",
    "src/Finance/Routes/Checkout.php",
    "src/Purchase/EntryAndBulk.php",
    "src/Purchase/TrialProvision.php",
    "src/Purchase/Catalogue.php",
    "src/Purchase/SelectPlan.php",
    "src/Subscription/AccountTransfers.php",
    "src/Subscription/AccountDeletion.php",
    "src/Subscription/ConfigRefresh.php",
    "src/Subscription/CustomerRoutes.php",
    "src/Subscription/ExternalAccounts.php",
)
SENSITIVE_TERMS = (
    "مرزبان", "مارزبان", "Marzban", "MariaDB", "mysqli",
    "SQLSTATE", "database", "stack trace", "پنل",
)
LITERAL_MESSAGE = re.compile(r'\b(?:alert|sendMessage|editText)\s*\(\s*(["\'])(.*?)\1', re.S)


def verify(root: Path = ROOT, catalog: dict | None = None) -> dict:
    catalog = catalog_source.templates(root) if catalog is None else catalog
    examined = 0
    for rel in CUSTOMER_SURFACES:
        source = catalog_source.expanded((root / rel).read_text(), catalog)
        message_count = 0
        for match in LITERAL_MESSAGE.finditer(source):
            message_count += 1
            payload = match.group(2)
            leaking = [term for term in SENSITIVE_TERMS
                       if term.casefold() in payload.casefold()]
            if leaking:
                line = source.count("\n", 0, match.start()) + 1
                raise RuntimeError(
                    f"Customer error exposes infrastructure: {rel}:{line} {leaking}"
                )
        if not message_count:
            raise RuntimeError(f"Customer surface lacks auditable literal/catalog messages: {rel}")
        examined += message_count

    mixed = {
        "src/Finance/WalletSettlement.php": (
            "پرداخت این اشتراک در حال حاضر امکان‌پذیر نیست",
            "پرداخت این سفارش از کیف پول در حال حاضر امکان‌پذیر نیست",
        ),
        "src/Finance/Routes/RenewalCheckout.php": (
            "تمدید این اشتراک در حال حاضر امکان‌پذیر نیست",
            "نتیجه تمدید نیاز به بررسی دارد",
        ),
        "src/Finance/Routes/Extensions.php": (
            "افزایش حجم یا مدت برای این اشتراک در حال حاضر امکان‌پذیر نیست",
            "اگر پرداختی انجام داده‌اید، وضعیت آن را با پشتیبانی بررسی کنید",
        ),
    }
    for rel, expected in mixed.items():
        source = catalog_source.expanded((root / rel).read_text(), catalog)
        if not all(x in source for x in expected):
            raise RuntimeError("Mixed customer/admin financial copy regressed: " + rel)
    return {
        "complete": True,
        "customer_surface_files": len(CUSTOMER_SURFACES),
        "literal_first_argument_calls_inspected": examined,
        "mixed_financial_routes_copy_markers_checked": len(mixed),
        "coverage": "first-argument literal/resolved-catalog template inspection only; runtime fixtures required",
        "dynamic_audience_privacy_proved": False,
    }


if __name__ == "__main__":
    print(json.dumps(verify(), ensure_ascii=False))
