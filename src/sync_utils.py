from __future__ import annotations


_PLACEHOLDER_PREFIXES = (
    "replace_with_",
    "replace-me",
    "replace_me",
    "your_",
    "your-",
    "your ",
    "changeme",
    "change_me",
    "change-me",
)

_PLACEHOLDER_VALUES = {
    "<replace>",
    "<replace_me>",
    "<replace-with-value>",
    "<todo>",
    "todo",
    "placeholder",
}


def is_unconfigured_value(value: object | None) -> bool:
    text = str(value or "").strip()
    if text == "":
        return True

    normalized = text.lower()
    if normalized in _PLACEHOLDER_VALUES:
        return True

    return normalized.startswith(_PLACEHOLDER_PREFIXES)
