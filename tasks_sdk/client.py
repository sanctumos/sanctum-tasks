"""
Tasks API Client

Main client class for interacting with tasks.technonomicon.net API.
"""

import json
from typing import Optional, List, Dict, Any
from urllib.parse import urlencode
import requests

from .exceptions import (
    APIError,
    AuthenticationError,
    NotFoundError,
    ValidationError,
)


class TasksClient:
    """
    Client for interacting with tasks.technonomicon.net API.
    
    Args:
        api_key: Your API key from the admin panel
        base_url: Base URL of the tasks site (default: https://tasks.technonomicon.net)
    
    Example:
        >>> client = TasksClient(api_key="your_api_key_here")
        >>> task = client.create_task(
        ...     title="Fix deployment bug",
        ...     status="todo",
        ...     assigned_to_user_id=1
        ... )
    """
    
    def __init__(self, api_key: str, base_url: str = "https://tasks.technonomicon.net"):
        """
        Initialize the Tasks client.
        
        Args:
            api_key: Your API key from the admin panel
            base_url: Base URL of the tasks site (default: https://tasks.technonomicon.net)
        """
        self.api_key = api_key
        self.base_url = base_url.rstrip('/')
        self.api_base = f"{self.base_url}/api"
        self.session = requests.Session()
        self.session.headers.update({
            'X-API-Key': self.api_key,
            'Content-Type': 'application/json',
        })
    
    def _request(
        self,
        method: str,
        endpoint: str,
        params: Optional[Dict[str, Any]] = None,
        data: Optional[Dict[str, Any]] = None
    ) -> Dict[str, Any]:
        """
        Make an HTTP request to the API.
        
        Args:
            method: HTTP method (GET, POST, etc.)
            endpoint: API endpoint path (e.g., 'create-task.php')
            params: Query parameters
            data: Request body data
        
        Returns:
            Parsed JSON response
        
        Raises:
            AuthenticationError: If authentication fails
            NotFoundError: If resource not found
            ValidationError: If request validation fails
            APIError: For other API errors
        """
        url = f"{self.api_base}/{endpoint}"
        
        try:
            response = self.session.request(
                method=method,
                url=url,
                params=params,
                json=data,
                timeout=30
            )
        except requests.exceptions.RequestException as e:
            raise APIError(f"Request failed: {str(e)}")
        
        # Parse response
        try:
            response_data = response.json()
        except json.JSONDecodeError:
            raise APIError(f"Invalid JSON response: {response.text[:200]}")
        
        # Handle errors
        if response.status_code == 401:
            raise AuthenticationError(
                response_data.get('error', 'Authentication failed'),
                status_code=401,
                response=response_data
            )
        elif response.status_code == 404:
            raise NotFoundError(
                response_data.get('error', 'Resource not found'),
                status_code=404,
                response=response_data
            )
        elif response.status_code == 400:
            raise ValidationError(
                response_data.get('error', 'Validation failed'),
                status_code=400,
                response=response_data
            )
        elif not response.ok:
            error_msg = response_data.get('error', f'API error: {response.status_code}')
            raise APIError(
                error_msg,
                status_code=response.status_code,
                response=response_data
            )
        
        return response_data
    
    def health(self) -> Dict[str, Any]:
        """
        Check API health and get authenticated user info.
        
        Returns:
            Dictionary with health status and user info
        
        Example:
            >>> info = client.health()
            >>> print(info['user']['username'])
            'admin'
        """
        return self._request('GET', 'health.php')
    
    def create_task(
        self,
        title: str,
        status: Optional[str] = None,
        assigned_to_user_id: Optional[int] = None,
        body: Optional[str] = None
    ) -> Dict[str, Any]:
        """
        Create a new task.
        
        Args:
            title: Task title (required)
            status: Task status: 'todo', 'doing', or 'done' (default: 'todo')
            assigned_to_user_id: User ID to assign task to (optional)
            body: Task description/details (optional)
        
        Returns:
            Dictionary containing task data
        
        Example:
            >>> task = client.create_task(
            ...     title="Fix deployment bug",
            ...     status="todo",
            ...     assigned_to_user_id=1,
            ...     body="Check logs and deployment status"
            ... )
            >>> print(task['id'])
            123
        """
        data = {
            'title': title,
        }
        
        if status:
            data['status'] = status
        if assigned_to_user_id is not None:
            data['assigned_to_user_id'] = assigned_to_user_id
        if body is not None:
            data['body'] = body
        
        response = self._request('POST', 'create-task.php', data=data)
        return response['task']
    
    def update_task(
        self,
        task_id: int,
        title: Optional[str] = None,
        status: Optional[str] = None,
        assigned_to_user_id: Optional[int] = None,
        body: Optional[str] = None
    ) -> Dict[str, Any]:
        """
        Update an existing task.
        
        Args:
            task_id: Task ID (required)
            title: New title (optional, only updates if provided)
            status: New status: 'todo', 'doing', or 'done' (optional)
            assigned_to_user_id: New assigned user ID (optional, set to None to unassign)
            body: New body/description (optional, set to None or empty string to clear)
        
        Returns:
            Dictionary containing updated task data
        
        Example:
            >>> task = client.update_task(
            ...     task_id=123,
            ...     status="doing",
            ...     assigned_to_user_id=2,
            ...     body="Updated description"
            ... )
        """
        data = {'id': task_id}
        
        if title is not None:
            data['title'] = title
        if status is not None:
            data['status'] = status
        if assigned_to_user_id is not None:
            data['assigned_to_user_id'] = assigned_to_user_id
        if body is not None:
            data['body'] = body
        
        response = self._request('POST', 'update-task.php', data=data)
        return response['task']
    
    def get_task(self, task_id: int) -> Dict[str, Any]:
        """
        Get a single task by ID.
        
        Args:
            task_id: Task ID
        
        Returns:
            Dictionary containing task data
        
        Example:
            >>> task = client.get_task(123)
            >>> print(task['title'])
            'Fix deployment bug'
        """
        response = self._request('GET', 'get-task.php', params={'id': task_id})
        return response['task']
    
    def list_tasks(
        self,
        status: Optional[str] = None,
        assigned_to_user_id: Optional[int] = None,
        limit: Optional[int] = None,
        offset: int = 0
    ) -> Dict[str, Any]:
        """
        List tasks with optional filtering.
        
        Args:
            status: Filter by status: 'todo', 'doing', or 'done' (optional)
            assigned_to_user_id: Filter by assigned user ID (optional)
            limit: Maximum number of tasks to return (optional, max: 500, default: 100)
            offset: Number of tasks to skip (default: 0)
        
        Returns:
            Dictionary with 'tasks' list and 'count' integer
        
        Example:
            >>> result = client.list_tasks(status="todo", limit=10)
            >>> for task in result['tasks']:
            ...     print(task['title'])
        """
        params = {}
        if status:
            params['status'] = status
        if assigned_to_user_id is not None:
            params['assigned_to_user_id'] = assigned_to_user_id
        if limit is not None:
            params['limit'] = limit
        if offset > 0:
            params['offset'] = offset
        
        response = self._request('GET', 'list-tasks.php', params=params)
        return {
            'tasks': response['tasks'],
            'count': response['count']
        }
    
    def delete_task(self, task_id: int) -> bool:
        """
        Delete a task.
        
        Args:
            task_id: Task ID to delete
        
        Returns:
            True if successful
        
        Example:
            >>> client.delete_task(123)
            True
        """
        response = self._request('POST', 'delete-task.php', data={'id': task_id})
        return response.get('success', False)
