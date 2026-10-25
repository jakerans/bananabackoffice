<?php

namespace App\Support\Projects;

use Illuminate\Validation\ValidationException;

/**
 * A Brand Ambassador package (docs/slice-21-brand-ambassador-plan.md;
 * decision 175; decision 133; product-architecture.md, 6.10 and 10.2): a
 * discount on the sale in return for what the customer promises. The tiers
 * are Bronze, Silver and Gold; Custom is items from the list and a discount
 * up to the largest, with why.
 */
final readonly class AmbassadorPackage
{
    public const string NONE = 'none';

    public const string CUSTOM = 'custom';

    /** The largest discount: the floor price is the retail price less it (10.2). */
    public const int MAX_DISCOUNT_PERCENT = 25;

    /** The yard sign stays up this long (6.10). */
    public const int YARD_SIGN_DAYS = 30;

    public const int REASON_LENGTH = 1000;

    /** What a customer can promise, and how each is said, in English. */
    public const array ITEMS = [
        'yard_sign' => 'Yard sign, 30 days',
        'content_permission' => 'Permission for project content',
        'paper_survey' => 'Paper survey',
        'facebook_review' => 'Facebook review with before and after photos',
        'google_review' => 'Google review with before and after photos',
        'reference' => 'Named or phone reference',
        'video_testimonial' => 'Video testimonial',
        'social_post' => 'Personal social post',
    ];

    /**
     * The tiers, with their discount and items (decision 133).
     *
     * @var array<string, array{label: string, discount: int, items: list<string>}>
     */
    public const array TIERS = [
        self::NONE => ['label' => 'No package', 'discount' => 0, 'items' => []],
        'bronze' => ['label' => 'Bronze, 10%', 'discount' => 10, 'items' => ['yard_sign', 'content_permission', 'paper_survey']],
        'silver' => ['label' => 'Silver, 15%', 'discount' => 15, 'items' => ['yard_sign', 'content_permission', 'paper_survey', 'facebook_review', 'google_review', 'reference']],
        'gold' => ['label' => 'Gold, 25%', 'discount' => 25, 'items' => ['yard_sign', 'content_permission', 'paper_survey', 'facebook_review', 'google_review', 'reference', 'video_testimonial', 'social_post']],
        self::CUSTOM => ['label' => 'Custom', 'discount' => 0, 'items' => []],
    ];

    /**
     * @param  list<string>  $items
     */
    public function __construct(
        public string $tier,
        public int $discountPercent,
        public array $items,
        public ?string $reason = null,
    ) {}

    /**
     * Read a package as it is chosen on a form: ambassadorTier, and for
     * Custom, ambassadorDiscount, ambassadorItems and ambassadorReason.
     * Nothing when the form did not ask (a caller from before slice 21).
     *
     * @param  array<string, mixed>  $input
     * @param  string  $prefix  Put before each field's name in a refusal.
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input, string $prefix = ''): ?self
    {
        if (! array_key_exists('ambassadorTier', $input)) {
            return null;
        }

        $tier = (string) $input['ambassadorTier'];

        if (! isset(self::TIERS[$tier])) {
            throw ValidationException::withMessages([$prefix.'ambassadorTier' => __('Choose the Brand Ambassador package, or none.')]);
        }

        if ($tier !== self::CUSTOM) {
            return new self($tier, self::TIERS[$tier]['discount'], self::TIERS[$tier]['items']);
        }

        $discount = trim((string) ($input['ambassadorDiscount'] ?? ''));
        $chosen = is_array($input['ambassadorItems'] ?? null) ? $input['ambassadorItems'] : [];
        $items = array_values(array_filter(array_keys(self::ITEMS), fn (string $item): bool => in_array($item, $chosen, true)));
        $reason = trim((string) ($input['ambassadorReason'] ?? ''));

        if (preg_match('/^\d{1,2}$/', $discount) !== 1 || (int) $discount < 1 || (int) $discount > self::MAX_DISCOUNT_PERCENT) {
            throw ValidationException::withMessages([$prefix.'ambassadorDiscount' => __('Give the discount as a whole percent from 1 to :max.', ['max' => self::MAX_DISCOUNT_PERCENT])]);
        }

        if ($items === []) {
            throw ValidationException::withMessages([$prefix.'ambassadorItems' => __('Choose what the customer promises.')]);
        }

        if ($reason === '' || mb_strlen($reason) > self::REASON_LENGTH) {
            throw ValidationException::withMessages([$prefix.'ambassadorReason' => $reason === '' ? __('Say why the package is custom.') : __('Keep this to :max characters or fewer.', ['max' => self::REASON_LENGTH])]);
        }

        return new self(self::CUSTOM, (int) $discount, $items, $reason);
    }

    /**
     * The price before the discount, from the price it sold for.
     */
    public function retailCents(int $soldCents): int
    {
        return $this->discountPercent === 0 ? $soldCents : (int) round($soldCents * 100 / (100 - $this->discountPercent));
    }

    public function label(): string
    {
        return $this->tier === self::CUSTOM ? 'Custom, '.$this->discountPercent.'%' : self::TIERS[$this->tier]['label'];
    }

    public static function itemLabel(string $item): string
    {
        return self::ITEMS[$item] ?? $item;
    }
}
