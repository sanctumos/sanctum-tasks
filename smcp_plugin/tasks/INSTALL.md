# Tasks SMCP Plugin Installation Guide

## Quick Installation

1. **Copy the plugin to your SMCP plugins directory:**
   ```bash
   cp -r smcp_plugin/tasks /path/to/smcp/plugins/
   ```

2. **Make the CLI executable:**
   ```bash
   chmod +x /path/to/smcp/plugins/tasks/cli.py
   ```

3. **Install Python dependencies:**
   ```bash
   cd /path/to/smcp/plugins/tasks
   pip install -r requirements.txt
   ```

4. **Ensure the Tasks SDK is accessible:**
   
   The plugin needs access to the `tasks_sdk` package. You have two options:
   
   **Option A: Copy SDK to plugin directory (Recommended for standalone)**
   ```bash
   cp -r ../../tasks_sdk /path/to/smcp/plugins/tasks/
   ```
   
   **Option B: Install SDK as a package**
   ```bash
   cd ../../tasks_sdk
   pip install -e .
   ```

5. **Restart the SMCP server**

## Configuration

### Configuration

- **Base URL**: Hard-coded to `https://tasks.technonomicon.net`
- **API Key**: Must be provided as `--api-key` argument for all commands

### Getting an API Key

1. Navigate to your tasks admin panel: `https://tasks.technonomicon.net/admin/login.php`
2. Login with your admin credentials (bootstrap password is configured or generated at first run)
3. Bootstrap API key may be present in `db/api_key.txt` on the server
4. Create dedicated API keys through the admin interface
5. **Copy keys immediately** - full value is shown only at creation time

## Testing

Test the plugin directly:

```bash
# Test plugin description
python /path/to/smcp/plugins/tasks/cli.py --describe

# Test listing tasks
python /path/to/smcp/plugins/tasks/cli.py list-tasks --api-key "YOUR_API_KEY" --limit 5

# Test creating a task
python /path/to/smcp/plugins/tasks/cli.py create-task \
  --api-key "YOUR_API_KEY" \
  --title "Test Task" \
  --status "todo"
```

## Integration with SMCP

Once installed, the plugin will be automatically discovered by SMCP. The following tools will be available to AI clients:

- `tasks.create-task` - Create new tasks
- `tasks.update-task` - Update existing tasks
- `tasks.list-tasks` - List tasks with filtering
- `tasks.get-task` - Get a single task
- `tasks.delete-task` - Delete a task

## Troubleshooting

### Plugin not discovered

- Check that `cli.py` exists and is executable
- Verify the plugin directory structure
- Check SMCP server logs for discovery errors

### Import errors

- Ensure `tasks_sdk` is accessible (see installation step 4)
- Check that all dependencies are installed: `pip install -r requirements.txt`

### API errors

- Verify API key is provided correctly with `--api-key` argument
- Base URL is hard-coded to `https://tasks.technonomicon.net`
- Test the API directly with curl to verify connectivity

### Command execution errors

- Test commands directly: `python cli.py <command> --help`
- Check SMCP server logs for detailed error messages
- Verify API key has proper permissions

## Next Steps

After installation, the plugin is ready to use. AI agents can now interact with your tasks system through the MCP protocol!
