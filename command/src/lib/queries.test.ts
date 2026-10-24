import { describe, expect, it } from 'vitest'
import { queryKeys } from './queries'

describe('query keys', () => {
  // Regression: the overview (per_page=1) and members page (per_page=25)
  // once shared a cache entry, so the members list showed one row and was
  // not refreshed after adding a member.
  it('distinguishes list requests by page size', () => {
    expect(queryKeys.members('org-1', 1, 1)).not.toEqual(queryKeys.members('org-1', 1, 25))
  })

  it('keeps member lists under the organisation prefix used for invalidation', () => {
    expect(queryKeys.members('org-1', 2, 25).slice(0, 3)).toEqual(['organisations', 'org-1', 'members'])
  })
})
