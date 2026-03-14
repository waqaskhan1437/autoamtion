# Video Workflow Manager - Test Environment Setup

## Overview
This document provides a comprehensive guide to setting up the test environment for the Video Workflow Manager system.

## Prerequisites

### System Requirements
- **Operating System**: Windows, macOS, or Linux
- **Node.js**: v16.0.0 or higher
- **MySQL**: v8.0.0 or higher
- **ffmpeg**: v4.0.0 or higher
- **npm**: v7.0.0 or higher

### Software Installation

#### 1. Node.js
Download and install from: https://nodejs.org/

#### 2. MySQL
- **Windows**: Install XAMPP or MySQL Community Server
- **macOS**: Install MySQL Community Server or use Homebrew
- **Linux**: Install using package manager (e.g., `sudo apt install mysql-server`)

#### 3. ffmpeg
- **Windows**: Download from https://ffmpeg.org/
- **macOS**: `brew install ffmpeg`
- **Linux**: `sudo apt install ffmpeg`

## Setup Instructions

### Step 1: Clone Repository
```bash
git clone <repository-url>
cd autoamtion-push
```

### Step 2: Install Dependencies
```bash
npm install
```

### Step 3: Set Up Test Database
```bash
# Create test database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS video_workflow_test;"

# Import test schema
mysql -u root video_workflow_test < test/database/test-database-schema.sql
```

### Step 4: Configure Environment
```bash
# Copy example environment files
cp test/config/.env.test.example test/config/.env.test
cp test/config/database.test.example.json test/config/database.test.json

# Edit configuration files with your settings
```

### Step 5: Generate Test Data
```bash
cd test/scripts
npm install
npm run generate:all
cd ../..
```

### Step 6: Start Mock Services
```bash
# Start all mock services
cd test/mock-services
npm install
npm run start:all
cd ../..
```

### Step 7: Start Test Server
```bash
# Start the main application in test mode
npm run start:test
```

## Configuration Files

### Environment Variables (.env.test)
```bash
NODE_ENV=test
PORT=3000
DB_HOST=localhost
DB_PORT=3306
DB_NAME=video_workflow_test
DB_USER=test_user
DB_PASSWORD=test_password

# Mock Services URLs
POSTFORME_URL=http://localhost:3001
FTP_HOST=localhost
FTP_PORT=2121
GITHUB_URL=http://localhost:3002
SOCIAL_URL=http://localhost:3003

# Test Data Directories
TEST_VIDEO_DIR=./test/mock-data/videos
TEST_OUTPUT_DIR=./test/output

# Logging Configuration
LOG_LEVEL=debug
LOG_FILE=./logs/test.log
```

### Database Configuration (database.test.json)
```json
{
  "testDatabase": {
    "host": "localhost",
    "port": 3306,
    "database": "video_workflow_test",
    "user": "test_user",
    "password": "test_password",
    "charset": "utf8mb4"
  }
}
```

## Mock Services

### Available Services
1. **PostForMe Mock Server** (Port: 3001)
2. **FTP Mock Server** (Port: 2121)
3. **GitHub Actions Mock Server** (Port: 3002)
4. **Social Media Mock APIs** (Port: 3003)

### Starting Services
```bash
# Start all services
npm run start:mock-services

# Or start individually
npm run start:postforme
npm run start:ftp
npm run start:github
npm run start:social
```

## Test Data

### Generated Data
- **Users**: 10 test users with different roles
- **Videos**: 20 sample video files
- **Automations**: 5 test automation settings
- **Social Content**: 50 posts, 200 comments, 1000 likes
- **Processed Videos**: 10 processed video records
- **Logs**: 100 test log entries

### Data Location
- Test data: `test/mock-data/`
- Output files: `test/output/`
- Log files: `test/logs/`

## Running Tests

### Unit Tests
```bash
npm run test
```

### Integration Tests
```bash
npm run test:integration
```

### End-to-End Tests
```bash
npm run test:e2e
```

### Test Coverage
```bash
npm run test:coverage
```

## Development Workflow

### Starting Development Server
```bash
npm run dev
```

### Running in Test Mode
```bash
npm run start:test
```

### Monitoring Tests
```bash
npm run test:watch
```

## Troubleshooting

### Common Issues

1. **Database Connection Errors**
   - Ensure MySQL is running
   - Check database credentials in `.env.test`

2. **Port Conflicts**
   - Check if ports 3001-3003 are available
   - Change port numbers in configuration files

3. **Missing Dependencies**
   - Run `npm install` to install all dependencies
   - Check Node.js version compatibility

4. **Permission Issues**
   - Ensure proper file permissions for test directories
   - Run with appropriate user permissions

### Debug Mode
```bash
# Enable debug logging
export DEBUG=true
npm run start:test

# View logs
tail -f test/logs/test.log
```

## Performance Testing

### Load Testing
```bash
# Install load testing tools
npm install -g artillery

# Run load tests
artillery run test/performance/load-test.yml
```

### Stress Testing
```bash
# Run stress tests
npm run test:stress
```

## Security Considerations

### Test Data Security
- Test data contains no real user information
- Mock credentials are clearly marked as test data
- No production API keys are used

### Network Security
- Mock services run on localhost only
- No external network access required for testing
- All communication is encrypted where applicable

## Documentation

### API Documentation
- Generated API docs: `docs/api/`
- Test API endpoints: `test/mock-services/`

### Database Documentation
- Schema documentation: `test/database/`
- Migration scripts: `test/migrations/`

### Test Documentation
- Test cases: `test/cases/`
- Test scenarios: `test/scenarios/`

## Support

### Getting Help
- Check the troubleshooting section above
- Review the logs in `test/logs/`
- Check the GitHub issues for known problems

### Reporting Issues
- Create an issue in the GitHub repository
- Include relevant log files and error messages
- Provide steps to reproduce the issue

## License
This test environment is provided under the same license as the main project.