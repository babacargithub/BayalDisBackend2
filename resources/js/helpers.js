/**
 * Format a number as a currency amount in CFA
 * @param {number} amount - The amount to format
 * @returns {string} The formatted amount
 */
export const formatAmount = (amount) => {
    return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
}; 