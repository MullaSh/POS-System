import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { RupiahInput } from './rupiah-input'

function Harness() {
  const [v, setV] = useState<number | null>(null)
  return (
    <>
      <RupiahInput aria-label="Harga" value={v} onValueChange={setV} />
      <output>{String(v)}</output>
    </>
  )
}

describe('Isian Rupiah', () => {
  it('hanya menerima angka dan menampilkan titik ribuan', async () => {
    render(<Harness />)
    await userEvent.type(screen.getByLabelText('Harga'), '25a000')
    expect(screen.getByLabelText('Harga')).toHaveValue('25.000')
    expect(screen.getByRole('status')).toHaveTextContent('25000')
  })
})
