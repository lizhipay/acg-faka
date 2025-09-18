class Decimal {
    constructor(amount, scale = 2) {
        this.amount = amount;
        this.scale = scale;
    }

    toFixed(num, precision) {
        return Number(Math.round(parseFloat(num + 'e' + precision)) + 'e-' + precision).toFixed(precision);
    }

    add(other) {
        let scale = Math.pow(10, this.scale);
        let result = (Number(this.amount) * scale + Number(other) * scale) / scale;
        return new Decimal(this.toFixed(result, this.scale), this.scale);
    }

    sub(other) {
        let scale = Math.pow(10, this.scale);
        let result = (Number(this.amount) * scale - Number(other) * scale) / scale;
        return new Decimal(this.toFixed(result, this.scale), this.scale);
    }

    mul(factor) {
        let scale = Math.pow(10, this.scale);
        let result = Number(this.amount) * Number(factor);
        return new Decimal(this.toFixed(result, this.scale), this.scale);
    }

    div(divisor) {
        let result = Number(this.amount) / Number(divisor);
        return new Decimal(this.toFixed(result, this.scale), this.scale);
    }

    getAmount(scale = this.scale) {
        return this.toFixed(this.amount, scale);
    }
}