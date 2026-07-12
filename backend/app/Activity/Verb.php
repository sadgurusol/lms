<?php

namespace App\Activity;

enum Verb: string
{
    case SessionLaunched = 'session.launched';
    case ContentViewed = 'content.viewed';
    case ContentProgressed = 'content.progressed';
    case ContentCompleted = 'content.completed';
    case VideoWatched = 'video.watched';
    case CourseCompleted = 'course.completed';
    case AttemptStarted = 'attempt.started';
    case AttemptSubmitted = 'attempt.submitted';
    case AttemptGraded = 'attempt.graded';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(fn (self $v) => $v->value, self::cases());
    }

    /** The xAPI verb IRI. Unmapped verbs get our own namespace rather than a lie. */
    public function iri(): string
    {
        return match ($this) {
            self::ContentViewed => 'http://id.tincanapi.com/verb/viewed',
            self::ContentProgressed => 'http://adlnet.gov/expapi/verbs/progressed',
            self::ContentCompleted, self::CourseCompleted => 'http://adlnet.gov/expapi/verbs/completed',
            self::AttemptStarted => 'http://adlnet.gov/expapi/verbs/attempted',
            self::AttemptSubmitted => 'http://adlnet.gov/expapi/verbs/answered',
            self::AttemptGraded => 'http://adlnet.gov/expapi/verbs/scored',
            self::VideoWatched => 'https://w3id.org/xapi/video/verbs/played',
            self::SessionLaunched => 'http://adlnet.gov/expapi/verbs/launched',
        };
    }

    public function display(): string
    {
        return match ($this) {
            self::ContentViewed => 'viewed',
            self::ContentProgressed => 'progressed',
            self::ContentCompleted, self::CourseCompleted => 'completed',
            self::AttemptStarted => 'attempted',
            self::AttemptSubmitted => 'answered',
            self::AttemptGraded => 'scored',
            self::VideoWatched => 'played',
            self::SessionLaunched => 'launched',
        };
    }
}
