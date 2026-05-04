"""
Tasks API Client

Main client class for interacting with the Sanctum Tasks API.
"""

import json
from typing import Optional, Dict, Any, List
import requests

from .exceptions import (
    APIError,
    AuthenticationError,
    NotFoundError,
    ValidationError,
)


class TasksClient:
    """
    Client for interacting with the Sanctum Tasks API.
    
    Args:
        api_key: Your API key from the admin panel
        base_url: Base URL of the Sanctum Tasks server (default: https://tasks.example.com)
    
    Example:
        >>> client = TasksClient(api_key="your_api_key_here")
        >>> task = client.create_task(
        ...     title="Fix deployment bug",
        ...     status="todo",
        ...     assigned_to_user_id=1
        ... )
    """
    
    def __init__(self, api_key: str, base_url: str = "https://tasks.example.com"):
        """
        Initialize the Tasks client.
        
        Args:
            api_key: Your API key from the admin panel
            base_url: Base URL of the Sanctum Tasks server (default: https://tasks.example.com)
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
        data: Optional[Dict[str, Any]] = None,
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
            response = self.session.request(method=method, url=url, params=params, json=data, timeout=30)
        except requests.exceptions.RequestException as e:
            raise APIError(f"Request failed: {str(e)}")

        # Parse response
        try:
            response_data = response.json()
        except ValueError:
            raise APIError(f"Invalid JSON response: {response.text[:200]}")

        error_obj = response_data.get("error_object") if isinstance(response_data, dict) else None
        error_msg = (
            response_data.get("error")
            if isinstance(response_data, dict)
            else None
        ) or (
            error_obj.get("message")
            if isinstance(error_obj, dict)
            else None
        ) or f"API error: {response.status_code}"

        # Handle errors
        if response.status_code == 401:
            raise AuthenticationError(
                error_msg,
                status_code=401,
                response=response_data,
            )
        elif response.status_code == 404:
            raise NotFoundError(
                error_msg,
                status_code=404,
                response=response_data,
            )
        elif response.status_code in (400, 405, 422):
            raise ValidationError(
                error_msg,
                status_code=response.status_code,
                response=response_data,
            )
        elif response.status_code == 429:
            raise APIError(
                error_msg,
                status_code=429,
                response=response_data,
            )
        elif not response.ok:
            raise APIError(
                error_msg,
                status_code=response.status_code,
                response=response_data,
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
        body: Optional[str] = None,
        due_at: Optional[str] = None,
        priority: Optional[str] = None,
        project: Optional[str] = None,
        tags: Optional[List[str]] = None,
        rank: Optional[int] = None,
        recurrence_rule: Optional[str] = None,
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
        data = {'title': title}

        if status:
            data['status'] = status
        if assigned_to_user_id is not None:
            data['assigned_to_user_id'] = assigned_to_user_id
        if body is not None:
            data['body'] = body
        if due_at is not None:
            data['due_at'] = due_at
        if priority is not None:
            data['priority'] = priority
        if project is not None:
            data['project'] = project
        if tags is not None:
            data['tags'] = tags
        if rank is not None:
            data['rank'] = rank
        if recurrence_rule is not None:
            data['recurrence_rule'] = recurrence_rule

        response = self._request('POST', 'create-task.php', data=data)
        return response['task']

    def update_task(
        self,
        task_id: int,
        title: Optional[str] = None,
        status: Optional[str] = None,
        assigned_to_user_id: Optional[int] = None,
        body: Optional[str] = None,
        due_at: Optional[str] = None,
        priority: Optional[str] = None,
        project: Optional[str] = None,
        tags: Optional[List[str]] = None,
        rank: Optional[int] = None,
        recurrence_rule: Optional[str] = None,
        unassign: bool = False,
        clear_body: bool = False,
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
        if unassign:
            data['assigned_to_user_id'] = None
        elif assigned_to_user_id is not None:
            data['assigned_to_user_id'] = assigned_to_user_id
        if clear_body:
            data['body'] = None
        elif body is not None:
            data['body'] = body
        if due_at is not None:
            data['due_at'] = due_at
        if priority is not None:
            data['priority'] = priority
        if project is not None:
            data['project'] = project
        if tags is not None:
            data['tags'] = tags
        if rank is not None:
            data['rank'] = rank
        if recurrence_rule is not None:
            data['recurrence_rule'] = recurrence_rule

        response = self._request('POST', 'update-task.php', data=data)
        return response['task']

    def get_task(self, task_id: int, include_relations: bool = True) -> Dict[str, Any]:
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
        response = self._request(
            'GET',
            'get-task.php',
            params={'id': task_id, 'include_relations': 1 if include_relations else 0},
        )
        return response['task']

    def list_tasks(
        self,
        status: Optional[str] = None,
        assigned_to_user_id: Optional[int] = None,
        created_by_user_id: Optional[int] = None,
        priority: Optional[str] = None,
        project: Optional[str] = None,
        q: Optional[str] = None,
        due_before: Optional[str] = None,
        due_after: Optional[str] = None,
        watcher_user_id: Optional[int] = None,
        sort_by: Optional[str] = None,
        sort_dir: Optional[str] = None,
        limit: Optional[int] = None,
        offset: int = 0,
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
        if created_by_user_id is not None:
            params['created_by_user_id'] = created_by_user_id
        if priority is not None:
            params['priority'] = priority
        if project is not None:
            params['project'] = project
        if q is not None:
            params['q'] = q
        if due_before is not None:
            params['due_before'] = due_before
        if due_after is not None:
            params['due_after'] = due_after
        if watcher_user_id is not None:
            params['watcher_user_id'] = watcher_user_id
        if sort_by is not None:
            params['sort_by'] = sort_by
        if sort_dir is not None:
            params['sort_dir'] = sort_dir
        if limit is not None:
            params['limit'] = limit
        if offset >= 0:
            params['offset'] = offset

        response = self._request('GET', 'list-tasks.php', params=params)
        return {
            'tasks': response['tasks'],
            'count': response['count'],
            'total': response.get('total', response.get('count', 0)),
            'pagination': response.get('pagination'),
        }

    def search_tasks(self, q: str, **kwargs) -> Dict[str, Any]:
        params = {'q': q}
        params.update(kwargs)
        response = self._request('GET', 'search-tasks.php', params=params)
        return {
            'tasks': response.get('tasks', []),
            'count': response.get('count', 0),
            'total': response.get('total', 0),
            'pagination': response.get('pagination'),
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
        return bool(response.get('success', False))

    # ---------- Bulk ----------
    def bulk_create_tasks(self, tasks: List[Dict[str, Any]]) -> Dict[str, Any]:
        return self._request('POST', 'bulk-create-tasks.php', data={'tasks': tasks})

    def bulk_update_tasks(self, updates: List[Dict[str, Any]]) -> Dict[str, Any]:
        return self._request('POST', 'bulk-update-tasks.php', data={'updates': updates})

    # ---------- Statuses ----------
    def list_statuses(self) -> List[Dict[str, Any]]:
        response = self._request('GET', 'list-statuses.php')
        return response.get('statuses', [])

    def create_status(
        self,
        slug: str,
        label: str,
        sort_order: int = 100,
        is_done: bool = False,
        is_default: bool = False,
    ) -> Dict[str, Any]:
        response = self._request(
            'POST',
            'create-status.php',
            data={
                'slug': slug,
                'label': label,
                'sort_order': sort_order,
                'is_done': is_done,
                'is_default': is_default,
            },
        )
        return response.get('status', {})

    # ---------- Users ----------
    def list_users(self, include_disabled: bool = False) -> List[Dict[str, Any]]:
        response = self._request(
            'GET',
            'list-users.php',
            params={'include_disabled': 1 if include_disabled else 0},
        )
        return response.get('users', [])

    def create_user(
        self,
        username: str,
        password: str,
        role: str = 'member',
        must_change_password: bool = True,
        create_api_key: bool = False,
        api_key_name: str = 'default',
        org_id: Optional[int] = None,
        person_kind: Optional[str] = None,
    ) -> Dict[str, Any]:
        payload = {
            'username': username,
            'password': password,
            'role': role,
            'must_change_password': must_change_password,
            'create_api_key': create_api_key,
            'api_key_name': api_key_name,
        }
        if org_id is not None:
            payload['org_id'] = org_id
        if person_kind is not None:
            payload['person_kind'] = person_kind
        return self._request('POST', 'create-user.php', data=payload)

    def disable_user(self, user_id: int, is_active: bool = False) -> Dict[str, Any]:
        return self._request('POST', 'disable-user.php', data={'id': user_id, 'is_active': is_active})

    def reset_user_password(
        self,
        user_id: int,
        new_password: Optional[str] = None,
        must_change_password: bool = True,
    ) -> Dict[str, Any]:
        payload = {'id': user_id, 'must_change_password': must_change_password}
        if new_password is not None:
            payload['new_password'] = new_password
        return self._request('POST', 'reset-user-password.php', data=payload)

    # ---------- API keys ----------
    def list_api_keys(self, include_revoked: bool = False, mine: bool = False) -> List[Dict[str, Any]]:
        response = self._request(
            'GET',
            'list-api-keys.php',
            params={'include_revoked': 1 if include_revoked else 0, 'mine': 1 if mine else 0},
        )
        return response.get('api_keys', [])

    def create_api_key(self, key_name: str, user_id: Optional[int] = None) -> Dict[str, Any]:
        payload = {'key_name': key_name}
        if user_id is not None:
            payload['user_id'] = user_id
        return self._request('POST', 'create-api-key.php', data=payload)

    def revoke_api_key(self, key_id: int) -> Dict[str, Any]:
        return self._request('POST', 'revoke-api-key.php', data={'id': key_id})

    # ---------- Collaboration ----------
    def list_comments(self, task_id: int, limit: int = 100, offset: int = 0) -> List[Dict[str, Any]]:
        response = self._request(
            'GET',
            'list-comments.php',
            params={'task_id': task_id, 'limit': limit, 'offset': offset},
        )
        return response.get('comments', [])

    def create_comment(self, task_id: int, comment: str) -> Dict[str, Any]:
        response = self._request('POST', 'create-comment.php', data={'task_id': task_id, 'comment': comment})
        return response.get('comment', {})

    def list_attachments(self, task_id: int) -> List[Dict[str, Any]]:
        response = self._request('GET', 'list-attachments.php', params={'task_id': task_id})
        return response.get('attachments', [])

    def add_attachment(
        self,
        task_id: int,
        file_name: str,
        file_url: str,
        mime_type: Optional[str] = None,
        size_bytes: Optional[int] = None,
    ) -> Dict[str, Any]:
        payload = {'task_id': task_id, 'file_name': file_name, 'file_url': file_url}
        if mime_type is not None:
            payload['mime_type'] = mime_type
        if size_bytes is not None:
            payload['size_bytes'] = size_bytes
        return self._request('POST', 'add-attachment.php', data=payload)

    def list_watchers(self, task_id: int) -> List[Dict[str, Any]]:
        response = self._request('GET', 'list-watchers.php', params={'task_id': task_id})
        return response.get('watchers', [])

    def watch_task(self, task_id: int, user_id: Optional[int] = None) -> Dict[str, Any]:
        payload = {'task_id': task_id}
        if user_id is not None:
            payload['user_id'] = user_id
        return self._request('POST', 'watch-task.php', data=payload)

    def unwatch_task(self, task_id: int, user_id: Optional[int] = None) -> Dict[str, Any]:
        payload = {'task_id': task_id}
        if user_id is not None:
            payload['user_id'] = user_id
        return self._request('POST', 'unwatch-task.php', data=payload)

    # ---------- Taxonomy ----------
    def list_projects(self, limit: int = 200) -> List[Dict[str, Any]]:
        response = self._request('GET', 'list-projects.php', params={'limit': limit})
        return response.get('projects', [])

    def list_organizations(self) -> List[Dict[str, Any]]:
        response = self._request('GET', 'list-organizations.php')
        return response.get('organizations', [])

    def list_directory_projects(self, limit: int = 200) -> List[Dict[str, Any]]:
        response = self._request('GET', 'list-directory-projects.php', params={'limit': limit})
        return response.get('projects', [])

    def create_directory_project(
        self,
        name: str,
        description: Optional[str] = None,
        client_visible: bool = False,
        all_access: bool = False,
    ) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            'name': name,
            'client_visible': client_visible,
            'all_access': all_access,
        }
        if description is not None:
            payload['description'] = description
        response = self._request('POST', 'create-directory-project.php', data=payload)
        data = response.get('data') or response
        return data.get('project', data) if isinstance(data, dict) else data

    def list_tags(self, limit: int = 200) -> List[Dict[str, Any]]:
        response = self._request('GET', 'list-tags.php', params={'limit': limit})
        return response.get('tags', [])

    # ---------- Auditing ----------
    def list_audit_logs(self, limit: int = 100, offset: int = 0) -> List[Dict[str, Any]]:
        response = self._request('GET', 'list-audit-logs.php', params={'limit': limit, 'offset': offset})
        return response.get('logs', [])
