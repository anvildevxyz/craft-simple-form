# Quizzes & Survey Reporting

Two complementary features turn a form into a questionnaire you can *measure*:

- **Quiz mode** scores each submission against an answer key you mark on the
  form's choice fields, with optional grade bands ("90+ = Excellent").
- **Survey reporting** aggregates all of a form's answers into per-question
  charts — option breakdowns, scale averages and distributions.

They are independent: a survey report works on any form, quiz or not.

## Quiz mode

> **Standard edition.** Quiz scoring requires [Standard](editions.md); survey reporting is available on both editions. See [Editions](editions.md).


### Turning it on

On the form's edit screen, under the **Rules** tab's **Quiz** section, toggle
**Score this form as a quiz** (`quizMode`).

### Marking correct answers

With quiz mode on, the option editor of every **choice field** (Select, Radio,
Checkbox) gains two extra controls per option:

- **Correct** — tick the option(s) that count as right answers.
- **Pts** — the points that option is worth (default **1**; no negative
  marking).

Only choice fields are scorable — ratings, opinion scales, text fields and the
rest are ignored by the scoring. A field's maximum is the sum of its correct
options' points; a respondent earns an option's points by selecting it (on a
Checkbox field, each correct selection scores independently).

### Grade bands

Optionally map percentages to labels in the **Grade bands** textarea — one band
per line as `<min-percent> <label>`:

```
90 Excellent
70 Pass
0 Fail
```

The highest band the percentage reaches wins. Leave it blank for a numeric
score only.

### Where the results go

Each submission is scored **once, at submit time**, and the result is stored
with it — editing the answer key later never rewrites past scores. The four
values (`quizScore`, `quizMaxScore`, `quizPercentage`, `quizGrade`) surface
everywhere a submission does:

- **CP detail** — a *Score* block ("7 / 10 (70%) — Pass") on the submission's
  detail screen.
- **CSV export** — *Score / Max score / Percentage / Grade* columns.
- **Success message & notifications** — the placeholders `{quizScore}`,
  `{quizMaxScore}`, `{quizPercentage}` and `{quizGrade}` interpolate into the
  form's success message and notification templates, e.g.
  *"You scored {quizScore} of {quizMaxScore} — {quizGrade}!"*.
- **Twig** — `submission.quizScore` etc. are element properties, readable
  wherever you have a submission.
- **GraphQL** — the `submitForm` mutation's payload carries `quizScore`,
  `quizMaxScore`, `quizPercentage` (0–100) and `quizGrade`, all `null` when the
  form isn't a quiz — so a headless client can show the result immediately. See
  [Twig & developer API](twig-and-api.md#graphql) and the
  [SDL](reference/schema.graphql).

There is no separate front-end results page: the score comes back in the submit
response (AJAX or GraphQL) and lives on the stored submission.

## Survey reporting

Every form has a **Report** tab (also reachable at
`Simple Form → Forms → <form> → Report`; needs the `viewSubmissions`
permission) that aggregates its non-spam submissions for the current site:

| Question type | Aggregate |
| --- | --- |
| **Choice** (Select, Radio, Checkbox) | Per-option response counts with percentage bars, in authored option order (zero-filled; a stored value no longer in the option set is appended by raw value). |
| **Scale** (Rating, Opinion Scale/NPS) | **Average** (1 decimal) plus a distribution chart across the scale's points. |
| **Free-form** (text, email, file, …) | Response count only — free-form answers aren't charted. |

Layout blocks are skipped. A **Responses** stat card heads the page, and a
`dateFrom` / `dateTo` **date-range filter** narrows the window. (Custom field
types opt into the charts via their `aggregation()` kind.)

For cross-form trends (per-day volume, spam rate, dispatch health) see the
[Analytics dashboard](submissions.md#analytics-dashboard); for per-submission
review, the [submissions index](submissions.md#the-submissions-index).
