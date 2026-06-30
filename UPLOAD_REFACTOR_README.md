# Upload System Refactor - Medical Plus v2

## Overview

The upload system has been completely refactored from an over-engineered chunked upload system to a simple, reliable direct file upload system suitable for a medical clinic application.

## What Was Removed

### Backend Complexity
- **MergeChunksJob** - Complex chunk merging background job
- **OptimizeVideoJob** - Unnecessary video processing pipeline
- **Upload sessions** - Session-based chunked upload tracking
- **Upload state management** - Complex state machine with queued/processing/merging states
- **HLS generation** - Video streaming capabilities not needed for medical files
- **Resume functionality** - Upload interruption recovery system
- **Complex video metadata** - Extensive video processing and analysis

### Frontend Complexity
- **Chunked upload logic** - File splitting and chunk management
- **Upload session persistence** - Cross-page upload state management
- **Resume capabilities** - Interrupted upload recovery
- **Complex progress tracking** - Multi-stage progress reporting
- **Polling for processing status** - Background job status monitoring

### Database Fields Removed
- `hls_path` - HLS streaming paths
- `duration`, `width`, `height` - Video metadata
- `video_metadata` - JSON video analysis data
- `processing_times` - Performance instrumentation

### Console Commands Removed
- `PurgeAbandonedUploads` - Cleanup for abandoned upload sessions
- `UploadPipelineReport` - Performance analytics for complex pipeline

## New Simplified System

### Upload Flow
1. **Select file** → User chooses file via drag/drop or file picker
2. **Upload directly** → File uploads immediately via single HTTP request
3. **Save immediately** → PatientFile record created instantly with `upload_status = 'ready'`
4. **Return success** → API immediately returns usable file data
5. **Video playable** → Videos are immediately playable via direct streaming
6. **Optional thumbnail** → Background thumbnail generation (non-blocking)

### Key Features
- **No chunking** - Direct file upload up to 500MB
- **Immediate availability** - Files are usable as soon as upload completes
- **Fail-safe thumbnails** - Thumbnail generation failure never blocks uploads
- **Simple status** - Only 'ready' or 'failed' upload statuses
- **Direct streaming** - Videos play directly without transcoding
- **Preserved security** - All existing permissions and access controls maintained

### API Changes

#### New Upload Endpoint
```
POST /api/v1/patients/{patient}/files
Content-Type: multipart/form-data

Parameters:
- file: The uploaded file
- title: Optional file title
- desc: Optional description  
- category: Optional file category
- date: Optional date

Response:
{
  "success": true,
  "message": "File uploaded successfully",
  "file": {
    "uuid": "...",
    "url": "...",
    "thumbnail_url": null,
    "upload_status": "ready"
  }
}
```

#### Removed Endpoints
- `POST /uploads/init` - Upload session initialization
- `POST /uploads/chunk` - Chunk upload
- `GET /uploads/status` - Upload progress polling
- `POST /uploads/cancel` - Upload cancellation
- `POST /uploads/complete` - Upload finalization
- `GET /files/{uuid}/hls/{path}` - HLS streaming

### Frontend Changes

#### New useUploads Composable
```javascript
import { useUploads } from '@/Composables/useUploads';

const { uploadFile, uploads } = useUploads();

// Upload a file
const uploadJob = uploadFile(file, patientId, { 
  title: 'X-Ray Results',
  category: 'radiology' 
});

// uploadJob.status will be 'uploading', 'completed', 'failed', or 'cancelled'
```

#### Simplified FileManager
- Direct file upload on selection
- No upload session management
- No polling for processing status
- Immediate file availability

### Database Migration

Run the migration to clean up the database:
```bash
php artisan migrate
```

This will:
- Remove unnecessary columns (`hls_path`, `video_metadata`, etc.)
- Add required columns (`mime_type`, `size`)
- Update existing records to 'ready' status
- Enforce non-nullable constraints on core fields

### Background Jobs

Only one background job remains:
- **GenerateThumbnailJob** - Optional thumbnail generation for videos
  - Runs after upload is already complete and marked 'ready'
  - Failure does not affect file usability
  - Uses simple FFmpeg frame extraction

## Benefits

### Reliability
- No race conditions between chunks
- No complex state management
- No session cleanup required
- Immediate file availability

### Simplicity  
- Single upload request
- Direct file storage
- Minimal background processing
- Easy to debug and maintain

### Performance
- No chunk assembly overhead
- No complex processing pipeline
- Immediate response to users
- Reduced server resource usage

### Maintainability
- Much less code to maintain
- Fewer background jobs to monitor
- Simpler error handling
- Clear separation of concerns

## Medical Clinic Context

This refactor recognizes that Medical Plus v2 is a clinic management system, not a video platform:

- **File sizes** are typically reasonable (documents, images, short videos)
- **Immediate access** to uploaded files is critical for patient care
- **Reliability** is more important than optimization
- **Simplicity** reduces operational overhead for medical staff
- **Security** and permissions are preserved throughout

## Migration Notes

### For Existing Installations
1. Run the database migration
2. Existing files remain fully functional
3. Old HLS files (if any) can be cleaned up manually
4. Upload sessions are no longer used and can be cleared

### For Development
1. Frontend automatically uses new upload system
2. Upload progress is now shown in simplified global manager
3. No need to handle complex upload states
4. Videos play immediately after upload

## Testing

The new system can be tested by:
1. Uploading various file types (images, videos, documents)
2. Verifying immediate file availability
3. Checking video playback works directly
4. Confirming thumbnail generation works in background
5. Testing with larger files up to 500MB limit

The simplified system prioritizes reliability and immediate usability over complex optimization features that were unnecessary for the medical clinic use case.