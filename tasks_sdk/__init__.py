"""
Tasks Python SDK

A Python client library for tasks.technonomicon.net API.
"""

from .client import TasksClient
from .exceptions import (
    TasksError,
    APIError,
    AuthenticationError,
    NotFoundError,
    ValidationError,
)

__version__ = "0.2.1"
__all__ = [
    "TasksClient",
    "TasksError",
    "APIError",
    "AuthenticationError",
    "NotFoundError",
    "ValidationError",
]
