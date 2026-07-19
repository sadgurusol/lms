// Plain data models parsed from the learner API. Kept deliberately thin — the
// server owns all the logic; the app renders what it is given.

class CourseSummary {
  CourseSummary({required this.id, required this.title, this.code, this.subject, this.gradeBand, this.publicationId});

  final String id;
  final String title;
  final String? code;
  final String? subject;
  final String? gradeBand;
  final String? publicationId;

  factory CourseSummary.fromJson(Map<String, dynamic> json) => CourseSummary(
    id: json['id'] as String,
    title: json['title'] as String,
    code: json['code'] as String?,
    subject: json['subject'] as String?,
    gradeBand: json['grade_band'] as String?,
    publicationId: json['publication_id'] as String?,
  );
}

/// One block of content on a node: rich text, callout, embed, etc.
class Block {
  Block({required this.id, required this.type, required this.payload});

  final String id;
  final String type;
  final Map<String, dynamic> payload;

  factory Block.fromJson(Map<String, dynamic> json) => Block(
    id: json['id'] as String,
    type: json['type'] as String,
    payload: (json['payload'] as Map?)?.cast<String, dynamic>() ?? const {},
  );
}

/// A node in the published course tree, with its numbering baked in.
class ContentNode {
  ContentNode({
    required this.id,
    required this.title,
    required this.label,
    this.summary,
    required this.blocks,
    required this.children,
  });

  final String id;
  final String title;
  final String label;
  final String? summary;
  final List<Block> blocks;
  final List<ContentNode> children;

  factory ContentNode.fromJson(Map<String, dynamic> json) => ContentNode(
    id: json['id'] as String,
    title: json['title'] as String,
    label: (json['label'] as String?) ?? json['title'] as String,
    summary: json['summary'] as String?,
    blocks: ((json['blocks'] as List?) ?? const [])
        .map((b) => Block.fromJson((b as Map).cast<String, dynamic>()))
        .toList(),
    children: ((json['children'] as List?) ?? const [])
        .map((c) => ContentNode.fromJson((c as Map).cast<String, dynamic>()))
        .toList(),
  );
}

/// Flatten a Portable Text document to plain paragraphs. Question stems are
/// short, so the app renders them as text rather than styled blocks.
String flattenPortableText(dynamic body) {
  if (body is! List) return '';
  return body
      .whereType<Map>()
      .map((blk) {
        final children = (blk['children'] as List?) ?? const [];
        return children.whereType<Map>().map((s) => s['text'] as String? ?? '').join();
      })
      .where((line) => line.isNotEmpty)
      .join('\n');
}

/// The learner's home dashboard, aggregated server-side.
class DashboardData {
  DashboardData({required this.stats, required this.courses, required this.recentQuizzes});

  final DashboardStats stats;
  final List<CourseProgress> courses;
  final List<RecentQuiz> recentQuizzes;

  factory DashboardData.fromJson(Map<String, dynamic> json) => DashboardData(
    stats: DashboardStats.fromJson((json['stats'] as Map).cast<String, dynamic>()),
    courses: ((json['courses'] as List?) ?? const [])
        .map((c) => CourseProgress.fromJson((c as Map).cast<String, dynamic>()))
        .toList(),
    recentQuizzes: ((json['recent_quizzes'] as List?) ?? const [])
        .map((q) => RecentQuiz.fromJson((q as Map).cast<String, dynamic>()))
        .toList(),
  );
}

class DashboardStats {
  DashboardStats({
    required this.coursesEnrolled,
    required this.coursesCompleted,
    required this.minutesSpent,
    required this.quizzesTaken,
    required this.quizzesPassed,
    this.averageQuizPercentage,
  });

  final int coursesEnrolled;
  final int coursesCompleted;
  final int minutesSpent;
  final int quizzesTaken;
  final int quizzesPassed;
  final double? averageQuizPercentage;

  factory DashboardStats.fromJson(Map<String, dynamic> json) => DashboardStats(
    coursesEnrolled: (json['courses_enrolled'] as num?)?.toInt() ?? 0,
    coursesCompleted: (json['courses_completed'] as num?)?.toInt() ?? 0,
    minutesSpent: (json['minutes_spent'] as num?)?.toInt() ?? 0,
    quizzesTaken: (json['quizzes_taken'] as num?)?.toInt() ?? 0,
    quizzesPassed: (json['quizzes_passed'] as num?)?.toInt() ?? 0,
    averageQuizPercentage: (json['average_quiz_percentage'] as num?)?.toDouble(),
  );
}

class CourseProgress {
  CourseProgress({
    required this.id,
    required this.title,
    this.subject,
    required this.percent,
    required this.completedNodes,
    required this.totalNodes,
  });

  final String id;
  final String title;
  final String? subject;
  final double percent;
  final int completedNodes;
  final int totalNodes;

  factory CourseProgress.fromJson(Map<String, dynamic> json) => CourseProgress(
    id: json['id'] as String,
    title: json['title'] as String,
    subject: json['subject'] as String?,
    percent: (json['percent'] as num?)?.toDouble() ?? 0,
    completedNodes: (json['completed_nodes'] as num?)?.toInt() ?? 0,
    totalNodes: (json['total_nodes'] as num?)?.toInt() ?? 0,
  );
}

class RecentQuiz {
  RecentQuiz({required this.title, required this.percentage, this.passed});

  final String title;
  final double percentage;
  final bool? passed;

  factory RecentQuiz.fromJson(Map<String, dynamic> json) => RecentQuiz(
    title: json['assessment_title'] as String? ?? 'Quiz',
    percentage: (json['percentage'] as num?)?.toDouble() ?? 0,
    passed: json['passed'] as bool?,
  );
}

/// One assessment on a course, plus what this learner has done with it.
class AssessmentSummary {
  AssessmentSummary({
    required this.id,
    required this.kind,
    required this.title,
    required this.questionCount,
    required this.attemptsUsed,
    required this.canStart,
    required this.passed,
    this.inProgressAttemptId,
    this.bestPercentage,
    this.timeLimitS,
    this.maxAttempts,
  });

  final String id;
  final String kind;
  final String title;
  final int questionCount;
  final int attemptsUsed;
  final bool canStart;
  final bool passed;
  final String? inProgressAttemptId;
  final double? bestPercentage;
  final int? timeLimitS;
  final int? maxAttempts;

  factory AssessmentSummary.fromJson(Map<String, dynamic> json) {
    final my = (json['my_state'] as Map?)?.cast<String, dynamic>() ?? const {};
    final settings = (json['settings'] as Map?)?.cast<String, dynamic>() ?? const {};
    return AssessmentSummary(
      id: json['id'] as String,
      kind: json['kind'] as String,
      title: json['title'] as String,
      questionCount: (json['question_count'] as num?)?.toInt() ?? 0,
      attemptsUsed: (my['attempts_used'] as num?)?.toInt() ?? 0,
      canStart: my['can_start'] as bool? ?? false,
      passed: my['passed'] as bool? ?? false,
      inProgressAttemptId: my['in_progress_attempt_id'] as String?,
      bestPercentage: (my['best_percentage'] as num?)?.toDouble(),
      timeLimitS: (settings['time_limit_s'] as num?)?.toInt(),
      maxAttempts: (settings['max_attempts'] as num?)?.toInt(),
    );
  }
}

class AttemptOption {
  AttemptOption({required this.id, required this.text, this.isCorrect});

  final String id;
  final String text;
  final bool? isCorrect; // present only once answers are revealed

  factory AttemptOption.fromJson(Map<String, dynamic> json) => AttemptOption(
    id: json['id'] as String,
    text: ((json['body'] as Map?)?['text'] as String?) ?? '',
    isCorrect: json['is_correct'] as bool?,
  );
}

class AttemptQuestion {
  AttemptQuestion({
    required this.assessmentQuestionId,
    required this.type,
    required this.stem,
    required this.points,
    required this.options,
    this.response,
    this.isCorrect,
    this.pointsAwarded,
  });

  final String assessmentQuestionId;
  final String type;
  final String stem;
  final double points;
  final List<AttemptOption> options;
  Map<String, dynamic>? response; // the learner's working answer
  final bool? isCorrect;
  final double? pointsAwarded;

  factory AttemptQuestion.fromJson(Map<String, dynamic> json) {
    final q = (json['question'] as Map).cast<String, dynamic>();
    return AttemptQuestion(
      assessmentQuestionId: json['assessment_question_id'] as String,
      type: q['type'] as String,
      stem: flattenPortableText(q['stem']?['body']),
      points: (q['points'] as num?)?.toDouble() ?? 0,
      options: ((q['options'] as List?) ?? const [])
          .map((o) => AttemptOption.fromJson((o as Map).cast<String, dynamic>()))
          .toList(),
      response: (json['response'] as Map?)?.cast<String, dynamic>(),
      isCorrect: json['is_correct'] as bool?,
      pointsAwarded: (json['points_awarded'] as num?)?.toDouble(),
    );
  }
}

class Attempt {
  Attempt({
    required this.id,
    required this.state,
    required this.questions,
    required this.answersRevealed,
    this.score,
    this.maxScore,
    this.passed,
    this.expiresAt,
    this.allowBacktrack = true,
  });

  final String id;
  final String state;
  final List<AttemptQuestion> questions;
  final bool answersRevealed;
  final double? score;
  final double? maxScore;
  final bool? passed;
  final DateTime? expiresAt; // when a timed attempt auto-submits
  final bool allowBacktrack;

  bool get isGraded => state == 'graded';
  bool get isInProgress => state == 'in_progress';

  factory Attempt.fromJson(Map<String, dynamic> json) => Attempt(
    id: json['id'] as String,
    state: json['state'] as String,
    answersRevealed: json['answers_revealed'] as bool? ?? false,
    score: (json['score'] as num?)?.toDouble(),
    maxScore: (json['max_score'] as num?)?.toDouble(),
    passed: json['passed'] as bool?,
    expiresAt: json['expires_at'] != null ? DateTime.tryParse(json['expires_at'] as String) : null,
    allowBacktrack: json['allow_backtrack'] as bool? ?? true,
    questions: ((json['questions'] as List?) ?? const [])
        .map((q) => AttemptQuestion.fromJson((q as Map).cast<String, dynamic>()))
        .toList(),
  );
}

/// A purchasable product in the storefront: the courses it unlocks and its
/// price options.
class CatalogProduct {
  CatalogProduct({
    required this.productId,
    required this.name,
    required this.owned,
    required this.courses,
    required this.plans,
  });

  final String productId;
  final String name;
  final bool owned;
  final List<CourseSummary> courses;
  final List<CatalogPlan> plans;

  factory CatalogProduct.fromJson(Map<String, dynamic> json) => CatalogProduct(
    productId: json['product_id'] as String,
    name: json['name'] as String,
    owned: json['owned'] as bool? ?? false,
    courses: ((json['courses'] as List?) ?? const [])
        .map((c) => CourseSummary.fromJson((c as Map).cast<String, dynamic>()))
        .toList(),
    plans: ((json['plans'] as List?) ?? const [])
        .map((p) => CatalogPlan.fromJson((p as Map).cast<String, dynamic>()))
        .toList(),
  );
}

/// One price option: a subscription interval or a one-time purchase.
class CatalogPlan {
  CatalogPlan({
    required this.code,
    required this.name,
    required this.priceMinor,
    required this.currency,
    required this.interval,
    required this.trialDays,
    required this.isSubscription,
  });

  final String code;
  final String name;
  final int priceMinor;
  final String currency;
  final String interval;
  final int trialDays;
  final bool isSubscription;

  /// Price formatted for display, e.g. "₹499" or "$12.00".
  String get priceLabel {
    final symbol = _currencySymbols[currency] ?? '$currency ';
    final major = priceMinor / 100;
    final amount = major == major.roundToDouble() ? major.toStringAsFixed(0) : major.toStringAsFixed(2);
    return '$symbol$amount';
  }

  String get intervalLabel => switch (interval) {
    'month' => '/mo',
    'year' => '/yr',
    _ => '',
  };

  factory CatalogPlan.fromJson(Map<String, dynamic> json) => CatalogPlan(
    code: json['code'] as String,
    name: json['name'] as String,
    priceMinor: (json['price_minor'] as num?)?.toInt() ?? 0,
    currency: json['currency'] as String? ?? 'INR',
    interval: json['interval'] as String? ?? 'month',
    trialDays: (json['trial_days'] as num?)?.toInt() ?? 0,
    isSubscription: json['is_subscription'] as bool? ?? true,
  );
}

const _currencySymbols = {'INR': '₹', 'USD': '\$', 'EUR': '€', 'GBP': '£'};

/// A past tutor conversation, for the history list.
class TutorConversationSummary {
  TutorConversationSummary({required this.id, this.title, this.createdAt});

  final String id;
  final String? title;
  final DateTime? createdAt;

  factory TutorConversationSummary.fromJson(Map<String, dynamic> json) => TutorConversationSummary(
    id: json['id'] as String,
    title: json['title'] as String?,
    createdAt: json['created_at'] != null ? DateTime.tryParse(json['created_at'] as String) : null,
  );
}

/// One turn in an AI-tutor conversation.
class TutorMessage {
  TutorMessage({required this.role, required this.content, this.citations = const []});

  final String role; // 'user' | 'assistant'
  final String content;
  final List<TutorCitation> citations;

  bool get isUser => role == 'user';

  factory TutorMessage.fromJson(Map<String, dynamic> json) => TutorMessage(
    role: json['role'] as String,
    content: json['content'] as String,
    citations: ((json['citations'] as List?) ?? const [])
        .map((c) => TutorCitation.fromJson((c as Map).cast<String, dynamic>()))
        .toList(),
  );
}

/// An event from the tutor's streamed reply.
sealed class TutorEvent {
  const TutorEvent();
}

/// One token of the assistant's reply.
class TutorDelta extends TutorEvent {
  const TutorDelta(this.text);
  final String text;
}

/// The stream is complete; carries the sections the tutor cited.
class TutorDone extends TutorEvent {
  const TutorDone(this.citations);
  final List<TutorCitation> citations;
}

/// A course section the tutor drew on, so the learner can find it.
class TutorCitation {
  TutorCitation({required this.id, required this.label});

  final String id;
  final String label;

  factory TutorCitation.fromJson(Map<String, dynamic> json) =>
      TutorCitation(id: json['id'] as String, label: json['label'] as String);
}

/// The whole published course: its metadata and tree.
class CourseContent {
  CourseContent({required this.title, required this.publicationId, required this.tree});

  final String title;
  final String? publicationId;
  final List<ContentNode> tree;

  factory CourseContent.fromJson(Map<String, dynamic> json) {
    final course = (json['course'] as Map?)?.cast<String, dynamic>() ?? const {};
    final publication = (json['publication'] as Map?)?.cast<String, dynamic>() ?? const {};
    return CourseContent(
      title: (course['title'] as String?) ?? 'Course',
      publicationId: publication['id'] as String?,
      tree: ((json['tree'] as List?) ?? const [])
          .map((n) => ContentNode.fromJson((n as Map).cast<String, dynamic>()))
          .toList(),
    );
  }

  /// Ids of content-bearing nodes, in reading order — the ones that count
  /// toward completion (a node with blocks). Matches the server's "trackable".
  List<String> get contentNodeIds {
    final ids = <String>[];
    void walk(ContentNode n) {
      if (n.blocks.isNotEmpty) ids.add(n.id);
      for (final c in n.children) {
        walk(c);
      }
    }

    for (final n in tree) {
      walk(n);
    }
    return ids;
  }
}
